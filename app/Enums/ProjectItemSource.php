<?php

namespace App\Enums;

enum ProjectItemSource: string
{
    case AiGenerated = 'ai_generated';
    case AgentEdited = 'agent_edited';
    case AgentAdded = 'agent_added';
    case AgentUpload = 'agent_upload';
    case ClientUpload = 'client_upload';
    case FacebookImport = 'facebook_import';
    case InstagramImport = 'instagram_import';
}
