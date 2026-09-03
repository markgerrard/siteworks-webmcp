<?php

namespace App\Enums;

enum ProjectItemStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
