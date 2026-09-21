<?php

declare(strict_types=1);

namespace Linkado\Laravel\Exceptions;

use LogicException;

final class ActiveTransactionRequired extends LogicException {}
