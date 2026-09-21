<?php

namespace Tests\Fixtures\Xacml;

use RuntimeException;

/** An attribute that is not there, or a division by zero: what XACML calls Indeterminate. */
final class Missing extends RuntimeException {}
