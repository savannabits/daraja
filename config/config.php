<?php

/*
 * You can place your custom package configuration in here.
 */
return [
    "mpesa" => [
        "cert_path" => storage_path("mpesa"),
        "live_cert_name" => "live.cer",
        "sandbox_cert_name" => "sandbox.cer"
    ]
];
