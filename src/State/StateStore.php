<?php

namespace AgilePixel\AllsWell\State;

interface StateStore
{
    /**
     * @return State State::empty() when nothing is stored.
     */
    public function load();

    /**
     * @return void
     *
     * @throws \RuntimeException When the state can't be stored.
     */
    public function save(State $state);

    /**
     * @return void
     */
    public function clear();

    /**
     * Where the state lives, as a lower-case noun phrase for messages. Never throws.
     *
     * @return string
     */
    public function name();

    /**
     * @return bool Whether the state outlives this process.
     */
    public function isPersistent();

    /**
     * Why the store can't be relied on, as one fixed sentence that names no
     * path: stored state that exists but can't be read, or a place it can't
     * be saved to. Never throws.
     *
     * @return string|null Null when nothing is wrong that can be seen before a save.
     */
    public function problem();

    /**
     * Never throws.
     *
     * @return bool Whether state is stored that load() can't read, so a pause in it would go unseen.
     */
    public function isUnreadable();
}
