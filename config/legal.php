<?php

/*
|--------------------------------------------------------------------------
| Terms and Consent
|--------------------------------------------------------------------------
|
| What a new account reads and signs on its second setup step. Change the
| wording here and raise `version`; each acceptance records the version it
| was given, so what someone agreed to is never in doubt.
|
*/

return [

    'version' => '2026-09-30.3',

    'terms' => [
        'title' => 'Terms of Service',
        'intro' => 'By accessing or using the Breeze.Ai application, you agree to be bound by these Terms of Service. These terms govern your use of the application, including all features, services, and content available through Breeze.Ai.',
        'points' => [
            'You are responsible for maintaining the confidentiality of your account credentials.',
            'You agree to use Breeze.Ai only for lawful purposes and in accordance with all applicable laws and regulations.',
            'You are responsible for the accuracy and completeness of the data you submit.',
            'You may not copy, modify, distribute, or reverse engineer any part of the application.',
            'Breeze.Ai may update these terms from time to time. Continued use of the application constitutes your acceptance of the updated terms.',
        ],
    ],

    'privacy' => [
        'title' => 'Privacy Summary',
        'intro' => 'Your privacy is important to us. This summary explains how we collect, use, and protect your information when you use Breeze.Ai.',
        'points' => [
            'We collect information you provide, such as your name, email address, and company details.',
            'We use your information to provide and improve our services, communicate with you, and ensure the security of the application.',
            'We do not sell your personal information to third parties.',
            'Your data is stored securely and access is limited to authorized personnel.',
            'You can contact us at any time to request access to, correction of, or deletion of your information.',
        ],
    ],
];
