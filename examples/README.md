# Examples

Runnable scripts against a real macula station. Build the library first
(`composer install && composer build`), then run an example with PHP. Each
reads where to connect from the environment, through [`mesh.php`](mesh.php):

```bash
export MACULA_SEED='station-fi-helsinki.macula.io:4433'   # the station, host:port
export MACULA_STATION_ID=<its node_id, 64 hex>           # it must prove it
export MACULA_REALM=<realm id, 64 hex>
export MACULA_REALM_KEY=<the realm's key as carried, hex> # the realm publishes it
php examples/01_quickstart.php
```

The node's key is created in `node.key` on first use (or `MACULA_KEY`),
readable by its owner only. The examples that need a second node use
`caller.key`.

A PHP process runs one thing at a time, so a node that serves is a worker of
its own: its loop takes each call with `handle()`. Call it from another
process.

| File | Covers | Needs |
|---|---|---|
| [01_quickstart.php](01_quickstart.php) | `NodeKey::loadOrCreate`, `Pool::connect`, `providers`, `call` by direct dial | nothing more |
| [02_serve.php](02_serve.php) | `serve`, a worker loop on `Served::handle` | for an org procedure, an org the realm admitted, delegated to this node |
| [03_publish_subscribe.php](03_publish_subscribe.php) | `subscribe`, `publish`, `Subscription::events` | nothing more |
| [04_stream_serve.php](04_stream_serve.php), [04_stream_open.php](04_stream_open.php) | `serveStream`, `openStream`, reading a stream | as 02 |
| [05_content.php](05_content.php) | `shareContent`, `getContent`, `unshareContent` | stations that admit a node's own namespace |
