<?php
// Conexión a MySQL. En el hosting solo se cambian estas 4 constantes.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'huracan';
const DB_USER = 'root';
const DB_PASS = '';

function conectar(): PDO {
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}