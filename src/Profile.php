<?php

declare(strict_types=1);

namespace Macula;

/** A node key's crypto profile: ML-DSA-87 with RSA-4096-PSS (pq_hybrid, the
 * fleet's), or ML-DSA-87 alone (pq_pure). */
enum Profile: string
{
    case PqHybrid = 'pq_hybrid';
    case PqPure = 'pq_pure';
}
