<?php

namespace App\Exceptions\Site;

class StaleStructureException extends StaleRevisionException // a stale structure_epoch IS a stale base for every existing catch; instanceof still separates them
{
}
