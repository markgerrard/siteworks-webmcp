<?php

namespace App\Enums;

enum AgentRole: string
{
    case Agent = 'agent';
    case Manager = 'manager';
    case SeniorManager = 'senior_manager';
    case Admin = 'admin';
}
