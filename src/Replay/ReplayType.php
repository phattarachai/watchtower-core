<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerCore\Replay;

enum ReplayType: string
{
    case Session = 'session';
    case Buffer = 'buffer';
}
