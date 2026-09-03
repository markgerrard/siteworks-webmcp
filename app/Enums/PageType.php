<?php

namespace App\Enums;

enum PageType: string
{
    case Home = 'home';
    case About = 'about';
    case Contact = 'contact';
    case Article = 'article';
    case Projects = 'projects';
}
