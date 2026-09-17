<?php

declare(strict_types=1);

/**
 * Example CV data.
 *
 * This file is delivered as-is and is safe to commit: it holds no private
 * information beyond what was already public in the source CV.
 *
 * To use your own data:
 *   1. Copy this file to "cv.php" at the project root (next to this file).
 *   2. Edit cv.php with your own information.
 *   3. cv.php is git-ignored and is always preferred over cv_example.php
 *      whenever both exist (see src/CvData.php) - the app "pulls from"
 *      cv.php, this file is only the fallback/example.
 *
 * Shape of the array:
 *
 *  - photo         : absolute filesystem path to the photo shown top-right.
 *  - header        : title / name / phone / email / website.
 *  - skills_left   : list of skill blocks stacked in the left skills column.
 *  - skills_right  : list of skill blocks stacked in the right skills column.
 *  - experiences   : list of work experience entries (left column).
 *  - education     : list of education entries (right column).
 *  - freetime      : the "Things I like to do in my free time" block,
 *                     pinned to the bottom-right corner of the page.
 *
 * Every "title" / "did" / "content" string may contain literal "\n"
 * characters to force a line break exactly where you want one (e.g. to
 * separate a course name from its organizing school on its own line).
 * Without any "\n", text simply wraps automatically to fit the column.
 *
 * Each skill block is either:
 *   ['heading' => '...', 'lines'     => [['label' => '...', 'value' => '...'], ...]]
 * or, for the languages block specifically:
 *   ['heading' => '...', 'languages' => [['code' => 'FR', 'label' => '...'], ...]]
 * 'code' is a 2-letter country code used to draw a small flag swatch
 * (FR, US, ES, CN, KE are drawn as simplified flags; any other code falls
 * back to a plain grey badge showing the code itself).
 */

return [
    'photo' => __DIR__ . '/cv_image.png',

    'header' => [
        'title'       => 'Software & Agentic Developer',
        'name'        => 'Pierre MINIGGIO',
        'phone'       => '+33 6 32 01 03 14',
        'email'       => 'pierre@miniggiodev.fr',
        'website'     => 'www.miniggiodev.fr',
        'website_url' => 'https://www.miniggiodev.fr',
    ],

    'skills_left' => [
        [
            'heading' => 'Software Development :',
            'lines' => [
                ['label' => 'Back End Web', 'value' => 'PHP, Symfony, Laravel, NodeJS, SQL'],
                ['label' => 'Front End Web', 'value' => 'HTML, CSS, SASS, Materialize, Bootstrap, JS, TS, React, npm, yarn'],
                ['label' => 'Code Design', 'value' => 'MVC, Procedural, Object, TDD, DDD'],
                ['label' => 'Methodology', 'value' => 'Agile, Scrum'],
                ['label' => 'Web Scraping', 'value' => 'Puppeteer'],
                ['label' => 'Content Generation', 'value' => 'Remotion, Imagick, ffmpeg'],
                ['label' => 'Coding with AI', 'value' => 'Claude, ChatGPT, Gemini API'],
            ],
        ],
        [
            'heading' => 'Languages :',
            'languages' => [
                ['code' => 'FR', 'label' => 'Native'],
                ['code' => 'US', 'label' => 'C2'],
                ['code' => 'ES', 'label' => 'B1 (Not well maintained)'],
                ['code' => 'CN', 'label' => 'A2'],
                ['code' => 'KE', 'label' => 'Beginner'],
            ],
        ],
    ],

    'skills_right' => [
        [
            'heading' => 'Infrastructure :',
            'lines' => [
                ['label' => 'Linux', 'value' => 'Setting up servers : Web, Database, Email, Proxy, etc.'],
                ['label' => 'AWS', 'value' => 'VTL, IAM, Lambda, API Gateway, etc.'],
                ['label' => 'Storage', 'value' => 'SQL, NoSQL, files, etc.'],
            ],
        ],
        [
            'heading' => "Softwares I'm used to :",
            'lines' => [
                ['label' => 'IDE', 'value' => 'Sublime Text, VS Code, PHPStorm'],
                ['label' => 'Image manipulation', 'value' => 'Paint.NET'],
                ['label' => 'Vidéo', 'value' => 'Sony Vegas Pro, XSplit, OBS'],
                ['label' => 'Audio', 'value' => 'FL Studio, Audacity'],
                ['label' => 'OS', 'value' => 'Windows, Linux'],
            ],
        ],
        [
            'heading' => 'Network and Telecommunication (Background) :',
            'lines' => [
                ['label' => 'Windows', 'value' => 'Active Directory'],
                ['label' => 'Virtualization', 'value' => 'Proxmox, VirtualBox'],
                ['label' => 'Router', 'value' => 'Cisco routers configuration : VLAN, QOS, etc.'],
            ],
        ],
    ],

    'experiences' => [
        [
            'logo'  => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-12/logo.png',
            'title' => 'Sept. 2025+ : MiniggioDev - Developer',
            'did'   => "Coding projects involving AI, and lots of traveling and adventures !\nPassword Manager, Loan & Investment Simulator, Wealth tracker, etc.",
            'used'  => 'Implementing AI into code design processes & for non-coding purposes',
        ],
        [
            'logo'           => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-10/santevet.png',
            'title'          => 'Oct. 2022 - Sept. 2025 : SantéVet - Developer Analyst',
            // This title is long: force it onto a single line (the generator
            // widens/shrinks it just enough to fit) instead of letting it wrap.
            'force_one_line' => true,
            'did'            => 'Developing and maintaining tools used by employees & partnered veterinarians',
            'used'           => "Rewriting legacy code from monolith to microservice based code on AWS\nImplementing TDD/DDD practices",
        ],
        [
            'logo'  => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-8/safti.png',
            'title' => 'Oct. 2019 - Oct. 2022 : SAFTI - Web Developer',
            'did'   => "Developing and maintaining tools used by 5000+ real estate agents and 200 headquarters' employees who support the agents (coaching, accounting, legal support, technical support)",
            'used'  => "Back : Refactoring old code and writing new one following TDD/DDD principles\nFront : Building new interfaces using React, depreciating then removing old ones",
        ],
        [
            'logo'  => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-7/agoravita.png',
            'title' => 'Aug. 2018 - Aug. 2019 : Agoravita - Web Developer',
            'did'   => 'Developing showcase websites & CRM for clients',
            'used'  => "Social Medias and GA APIs, RGPD Compliance, Page Speed\nMigrating code from a custom framework and PHP 5.6 to Laravel and PHP 7.2",
        ],
        [
            'logo'  => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-5/rtai-blog.png',
            'title' => 'Jan. - Apr. 2018 : Supervised project : Blog RTAI',
            'did'   => 'Setting up a Wordpress website',
            'used'  => 'Creating WordPress Plugins',
        ],
        [
            'logo'  => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-4/rail-concept.png',
            'title' => 'June 2017 : Rail Concept - 2 weeks internship',
            'did'   => null,
            'used'  => 'Frameworks Angular2 et Materialize',
        ],
        [
            'logo'  => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-2/prefecture-vaucluse.png',
            'title' => "Apr. - Sep. 2016 : IT Tech - Internship\nPréfecture de Vaucluse - SIDSIC department",
            'did'   => 'Technical support, PC deployment',
            'used'  => 'Developed and set up the hosting of a web app managing supply orders',
        ],
    ],

    'education' => [
        [
            'logo'    => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-9/sensio-labs.png',
            'title'   => '2020 : Symfony Course - Sensio Labs',
            'content' => "2 days Symfony course : an active contributor to the Symfony project welcomed us to the framework and explained us how some of the source code work.\n2 days advanced Symfony course : dove through the framework's key concepts, and introduced us to Symfony's Compiler pass.",
        ],
        [
            'logo'    => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-6/rtai.png',
            'title'   => "2018 : Bachelor's degree : LPRO RTAI\n(Responsable Technique d'Applications Internet)",
            'content' => "Lycée post-bac Saliège - Université Toulouse 1 Capitole\nHTML, CSS, JS, PHP, MySQL, Java, Bootstrap",
        ],
        [
            'logo'    => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-3/universite-grenoble-alpes.png',
            'title'   => "2016 : University Diploma in Technology\nRéseaux et Télécommunications",
            'content' => "Université Grenoble Alpes\nHTML, CSS, PHP, MySQL, Python, Java, Java for Android, Network related devices configuration, Setting up various Windows, Linux servers (Web, Email, DHCP, Streaming…)",
        ],
        [
            'logo'    => 'https://miniggiodev.fr/public/files/timeline/1/timeline-entry-1/lycee-jean-vilar.png',
            'title'   => "2014 : Baccalauréat Scientifique\nSpécialité Mathématiques",
            'content' => "Option Musique - Mention Assez Bien\nLycée Jean Vilar à Villeneuve-lès-Avignon\nDiscovering HTML on my free time",
        ],
    ],

    'freetime' => [
        'heading' => 'Things I like to do in my free time :',
        'lines' => [
            ['label' => 'Dev', 'value' => 'Side projects, examples : Built a web video editing software, Web scraping, APIs'],
            ['label' => 'Social Medias', 'value' => 'Building Youtube channels, Managing a dev community : "Les codeurs nomades" (FB)'],
            ['label' => 'Music', 'value' => 'Guitar, Bass, Music Production'],
            ['label' => 'Languages', 'value' => 'Taught myself US, currently learning other languages : ES, CN, KE'],
        ],
    ],
];
