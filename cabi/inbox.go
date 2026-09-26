package main

// #include <stdint.h>
import "C"

import (
	"context"
	"sync"
	"time"
)

// PHP cannot take a callback on a Go thread: PHP's FFI runs a C callback on
// the thread that calls it, and the engine is not safe to enter from another.
// So where macula-go hands something over asynchronously (a subscription's
// events, a served procedure's calls, a served stream's sessions), it goes
// into an inbox owned by the handle, and PHP takes it with a *_next call that
// waits at most a given time.

// inboxItem is one thing handed over: its JSON, and the handle it comes with
// (a pending call or a stream), or 0.
type inboxItem struct {
	handle uintptr
	json   string
}

// inboxState is what a next call found.
type inboxState int

const (
	inboxItemReady inboxState = iota // an item was taken
	inboxTimeout                     // nothing arrived within the wait
	inboxClosed                      // the inbox ended and holds nothing more
)

// inbox is a bounded queue with an end. A pusher waits while it is full, until
// its own context ends or the inbox closes; a closed inbox is drained before
// next reports that it ended.
type inbox struct {
	items chan inboxItem
	done  chan struct{}
	once  sync.Once
}

func newInbox(capacity int) *inbox {
	return &inbox{items: make(chan inboxItem, capacity), done: make(chan struct{})}
}

// push hands item over, and reports false when it could not: the inbox closed,
// or ctx ended while the inbox was full.
func (q *inbox) push(ctx context.Context, item inboxItem) bool {
	select {
	case <-q.done:
		return false
	default:
	}
	select {
	case q.items <- item:
		return true
	case <-q.done:
		return false
	case <-ctx.Done():
		return false
	}
}

// next takes the next item, waiting at most wait. A closed inbox still hands
// over what it holds first.
func (q *inbox) next(wait time.Duration) (inboxItem, inboxState) {
	select {
	case item := <-q.items:
		return item, inboxItemReady
	default:
	}
	timer := time.NewTimer(wait)
	defer timer.Stop()
	select {
	case item := <-q.items:
		return item, inboxItemReady
	case <-q.done:
		select {
		case item := <-q.items:
			return item, inboxItemReady
		default:
			return inboxItem{}, inboxClosed
		}
	case <-timer.C:
		return inboxItem{}, inboxTimeout
	}
}

// close ends the inbox; it is safe to call more than once.
func (q *inbox) close() { q.once.Do(func() { close(q.done) }) }

// inboxCapacity is how many events, calls or sessions wait for PHP to take
// them before a pusher waits: a served call then waits until its deadline.
const inboxCapacity = 256

// handedOver turns what next found into the C result: the item's JSON (and its
// handle through handleOut, when given), or NULL with *closed telling an end
// from a timeout.
func handedOver(item inboxItem, state inboxState, handleOut *C.uintptr_t, closed *C.int) *C.char {
	*closed = 0
	switch state {
	case inboxItemReady:
		if handleOut != nil {
			*handleOut = C.uintptr_t(item.handle)
		}
		return C.CString(item.json)
	case inboxClosed:
		*closed = 1
	}
	return nil
}
