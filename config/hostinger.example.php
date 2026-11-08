<?php
// Copy to config/local.php on the server only. Never commit real credentials.
return [
    'DB_HOST'=>'localhost',
    'DB_NAME'=>'REPLACE_DATABASE_NAME',
    'DB_USER'=>'REPLACE_DATABASE_USER',
    'DB_PASSWORD'=>'REPLACE_DATABASE_PASSWORD',
    'APP_SECURE_COOKIE'=>'1',
    'APP_ALLOW_INITIAL_SETUP'=>'0',
    'APP_HIDE_IMPORT_AFTER_COMPLETE'=>'1',
];
