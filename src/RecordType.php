<?php

declare(strict_types=1);

namespace Macula;

/** macula 12's DHT record types. */
enum RecordType: int
{
    case NodeRecord = 0x01;
    case ProcedureAdvertisement = 0x06;
    case Tombstone = 0x0c;
    case ContentAnnouncement = 0x11;
    case StationEndpoint = 0x12;
    case OrgDirectory = 0x15;
    case ProcedureDelegation = 0x16;
}
