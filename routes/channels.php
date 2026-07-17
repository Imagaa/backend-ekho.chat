<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('tenant.{tenantId}.chats', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});