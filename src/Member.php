<?php

declare(strict_types=1);

namespace QuickDns;

/**
 * A member of a group: a QuickDNS user, or someone invited who has not confirmed yet.
 */
final class Member
{
    /**
     * @param  int  $id  QuickDNS' id for the membership or the invitation
     * @param  string  $name  As it was entered, possibly empty
     * @param  bool  $confirmed  False while the invitation is not accepted
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $confirmed,
    ) {
    }
}
