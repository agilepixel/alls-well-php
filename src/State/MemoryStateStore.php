<?php

namespace AgilePixel\AllsWell\State;

/**
 * Keeps the state for this process only.
 */
final class MemoryStateStore implements StateStore
{
    /** @var State|null */
    private $state;

    public function load()
    {
        return $this->state !== null ? $this->state : State::empty();
    }

    public function save(State $state)
    {
        $this->state = $state;
    }

    public function clear()
    {
        $this->state = null;
    }

    public function name()
    {
        return 'the state';
    }

    public function isPersistent()
    {
        return false;
    }

    public function problem()
    {
        return null;
    }

    public function isUnreadable()
    {
        return false;
    }
}
