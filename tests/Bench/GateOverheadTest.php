<?php

namespace Tests\Bench;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Measures what Laravel Gate costs per check on its own, with a hook that does one isset().
 *
 * The number, about 2 microseconds, is the floor. Whatever the package adds sits on top of
 * it, so a budget for a permission check that ignores it cannot be met by any code.
 */
class GateOverheadTest extends TestCase
{
    public function test_gate_floor()
    {
        // A new Gate, not the one from the container: that one already carries the hook of the package.
        $user = new User;
        $gate = new \Illuminate\Auth\Access\Gate(app(), static fn () => $user);
        $set  = ['posts.view' => true];
        $gate->before(static fn (?Authorizable $u, string $a, array $args) => isset($set[$a]) ? true : null);

        $N = 100000;

        $t = hrtime(true);
        for ($i = 0; $i < $N; $i++) {
            isset($set['posts.view']);
        } $raw = (hrtime(true) - $t) / $N / 1000;
        $t     = hrtime(true);
        for ($i = 0; $i < $N; $i++) {
            $gate->allows('posts.view');
        } $hit = (hrtime(true) - $t) / $N / 1000;
        $t     = hrtime(true);
        for ($i = 0; $i < $N; $i++) {
            $gate->allows('posts.nope');
        } $miss = (hrtime(true) - $t) / $N / 1000;
        $t      = hrtime(true);
        for ($i = 0; $i < $N; $i++) {
            $gate->inspect('posts.view');
        } $can = (hrtime(true) - $t) / $N / 1000;

        fwrite(STDERR, sprintf("\nraw isset=%.3fus | Gate::allows hit=%.2fus miss=%.2fus | Gate::inspect hit=%.2fus\n", $raw, $hit, $miss, $can));
        $this->assertTrue(true);
    }
}
