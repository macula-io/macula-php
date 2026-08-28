/* Smoke test for libmacula.so's C ABI, independent of PHP -- proves the
 * cgo boundary and a real CONNECT/HELLO handshake work from genuine C
 * code before any PHP wrapper exists to test through. Not part of the
 * PHP package; a standalone verification tool.
 *
 * Build and run from cabi/:
 *   go build -buildmode=c-shared -o libmacula.so .
 *   cc -o testc/smoke testc/smoke.c -L. -lmacula -Wl,-rpath,.
 *   ./testc/smoke
 */
#include <stdio.h>
#include <stdlib.h>
#include "../libmacula.h"

static void print_hex(const char *label, unsigned char *buf, int n) {
    printf("%s", label);
    for (int i = 0; i < n; i++) printf("%02x", buf[i]);
    printf("\n");
}

int main(void) {
    char *err = NULL;

    uintptr_t identity = macula_identity_generate(&err);
    if (identity == 0) {
        fprintf(stderr, "identity_generate failed: %s\n", err);
        macula_free_string(err);
        return 1;
    }
    unsigned char node_id[32];
    macula_identity_node_id(identity, node_id);
    print_hex("identity node_id: ", node_id, 32);

    uintptr_t session = macula_connect(
        "station-de-frankfurt.macula.io", 4433, identity, 15000, &err);
    if (session == 0) {
        fprintf(stderr, "connect failed: %s\n", err);
        macula_free_string(err);
        macula_identity_free(identity);
        return 1;
    }

    int accepted = macula_session_accepted(session);
    unsigned char station_id[32];
    macula_session_station_node_id(session, station_id);
    printf("accepted: %d\n", accepted);
    print_hex("station node_id: ", station_id, 32);

    macula_session_close(session, identity);
    macula_identity_free(identity);

    if (!accepted) {
        fprintf(stderr, "station did not accept the connection\n");
        return 1;
    }
    printf("OK\n");
    return 0;
}
