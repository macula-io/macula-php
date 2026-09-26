package main

import (
	"context"
	"testing"
	"time"
)

// An item pushed is the next one taken, with its handle.
func TestAnInboxHandsOverWhatWasPushed(t *testing.T) {
	q := newInbox(4)
	if !q.push(context.Background(), inboxItem{handle: 7, json: `{"n":1}`}) {
		t.Fatal("push refused on an open inbox")
	}
	item, state := q.next(100 * time.Millisecond)
	if state != inboxItemReady || item.handle != 7 || item.json != `{"n":1}` {
		t.Fatalf("next = %+v, %v", item, state)
	}
}

// Nothing pushed within the wait is a timeout, not an end.
func TestAnEmptyInboxTimesOut(t *testing.T) {
	q := newInbox(4)
	start := time.Now()
	if _, state := q.next(50 * time.Millisecond); state != inboxTimeout {
		t.Fatalf("state = %v, want timeout", state)
	}
	if time.Since(start) < 40*time.Millisecond {
		t.Fatal("next returned before its wait was up")
	}
}

// A closed inbox still hands over what it holds, then says it has ended.
func TestAClosedInboxDrainsThenEnds(t *testing.T) {
	q := newInbox(4)
	q.push(context.Background(), inboxItem{json: "a"})
	q.close()
	if item, state := q.next(time.Second); state != inboxItemReady || item.json != "a" {
		t.Fatalf("first next after close = %+v, %v", item, state)
	}
	if _, state := q.next(time.Second); state != inboxClosed {
		t.Fatalf("state = %v, want closed", state)
	}
	if q.push(context.Background(), inboxItem{json: "b"}) {
		t.Fatal("push accepted on a closed inbox")
	}
}

// A full inbox makes the pusher wait, and a pusher whose context ends gives up:
// a served call nobody takes is answered at its deadline, not held forever.
func TestAPushIntoAFullInboxGivesUpWithItsContext(t *testing.T) {
	q := newInbox(1)
	q.push(context.Background(), inboxItem{json: "fills it"})
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()
	if q.push(ctx, inboxItem{json: "waits"}) {
		t.Fatal("push into a full inbox succeeded")
	}
}

// Closing wakes a pusher waiting on a full inbox.
func TestClosingReleasesAWaitingPusher(t *testing.T) {
	q := newInbox(1)
	q.push(context.Background(), inboxItem{json: "fills it"})
	done := make(chan bool)
	go func() { done <- q.push(context.Background(), inboxItem{json: "waits"}) }()
	time.Sleep(20 * time.Millisecond)
	q.close()
	select {
	case ok := <-done:
		if ok {
			t.Fatal("a push released by close reported success")
		}
	case <-time.After(time.Second):
		t.Fatal("close did not release the waiting pusher")
	}
}
