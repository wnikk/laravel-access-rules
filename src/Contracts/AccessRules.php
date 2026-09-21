<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Contracts;

/**
 * The entry point of version 2, kept as an empty shell so that code which names it breaks
 * loudly and in the right place.
 *
 * Version 2 described here an object with state: choose an owner, then act on it. Version 3
 * replaced it with Contracts\AccessManager, which keeps nothing between calls. The interface
 * has no methods on purpose. A project that type-hints it still loads, its container call
 * still answers with the AccessRules class, and every IDE and static analyser shows at once
 * that nothing may be called on it any more. Methods copied here would have promised that
 * version 2 still stands behind them.
 *
 * PHP allows the attribute #[\Deprecated] on functions, methods and constants only, so the
 * interface carries the PHPDoc tag, which the same tools read.
 *
 * @deprecated 3.0.0 Use Wnikk\LaravelAccessRules\Contracts\AccessManager, or the facade Wnikk\LaravelAccessRules\Facades\Access. Removed in 4.0.
 */
interface AccessRules {}
