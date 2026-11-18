<?php
// Configuració privada: les claus s'injecten com a variables d'entorn.
return [
    'brevo_api_key'      => getenv('BREVO_API_KEY') ?: '',
    'brevo_sender_email' => getenv('BREVO_SENDER_EMAIL') ?: 'u1995373@campus.udg.edu',
    'brevo_sender_name'  => getenv('BREVO_SENDER_NAME') ?: 'CML - Seguretat i Proteccio de Dades',
];
