<?php

Use Illuminate\Database\Schema\Builder;
Use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (Builder $schema) {
        if($schema->hasColumn('users', 'unread_messages'))
            return;

        $schema->table('users', function (Blueprint $table) {
            $table->integer('unread_messages')->default(0);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) {
            $table->dropColumn('unread_messages');
        });
    }
];