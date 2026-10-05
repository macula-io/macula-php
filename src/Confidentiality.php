<?php

declare(strict_types=1);

namespace Macula;

/**
 * How a call, stream or served procedure is kept (macula 13's end-to-end
 * seal). A call or open is sealed whenever the provider's advertisement names
 * a KEM key: Preferred (the default) calls a provider that names none in the
 * clear, Required fails with a ConfidentialityError instead. Off is for a
 * served procedure only: a call or open refuses it.
 */
enum Confidentiality: string
{
    case Preferred = 'preferred';
    case Required = 'required';
    case Off = 'off';
}
