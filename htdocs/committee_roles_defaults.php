<?php
// Shared default content for Committee Service Roles, used by both
// committee_roles.php (display) and api_committee_roles.php (for "Reset
// to defaults" and as the starting point the first time a role is
// edited). Only change this content here — never duplicate it separately
// in either of the other two files.

function standard_committee_roles() {
    return [
    [
        'naam' => 'PI Committee Chairperson',
        'sleutel' => 'chair',
        'sobriety' => '2 years continuous (suggested)',
        'ervaring' => '1 year committee service (suggested)',
        'termijn' => '1 year (suggested)',
        'taken' => [
            'Coordinates and directs all committee activities',
            'Sets the agenda and facilitates the meeting',
            'Familiarises themselves with PI guidelines and C.A.\'s 12 Traditions',
            'Attends all Area/District meetings, or designates someone from PI to attend',
            'Attends Area/District PI meetings, online working groups, and participates in area discussions and group conscience',
            'Joins and oversees the Area/District PI communication platform/forum/group',
            'Keeps in regular contact with all Area/District PI committee members',
            'Supports Area/District groups with any PI-related queries',
            'Seeks opportunities to cooperate with the professional community, other organisations, and the public at large',
        ],
    ],
    [
        'naam' => 'PI Treasurer',
        'sleutel' => 'treasurer',
        'sobriety' => '1 year continuous (suggested)',
        'ervaring' => '6 months committee service (suggested)',
        'termijn' => '1 year (suggested)',
        'taken' => [
            'Manages committee finances: income, expenses, and balance',
            'Reports the current financial position at every meeting ("Treasurer\'s Report")',
            'Keeps transparent, auditable records of all transactions',
            'Verifies reimbursement requests and resale entries submitted by members',
            'Ensures the committee stays fully self-supporting (Tradition 7) — no outside contributions',
            'Familiarises themselves with PI guidelines and C.A.\'s 12 Traditions',
        ],
    ],
    [
        'naam' => 'PI Secretary',
        'sleutel' => 'secretary',
        'sobriety' => '1 year continuous (suggested)',
        'ervaring' => '6 months committee service (suggested)',
        'termijn' => '1 year (suggested)',
        'taken' => [
            'Takes accurate minutes of every committee meeting',
            'Emails minutes to committee members promptly and uploads them to the committee\'s chosen platform',
            'Keeps a record of PI committee members\' start dates and contact details',
            'Attends all committee meetings',
            'Familiarises themselves with PI guidelines and C.A.\'s 12 Traditions',
            '(In this portal: also manages the Minutes &amp; Agendas archive in the Drive Database, and member profile data)',
        ],
    ],
    [
        'naam' => 'PI Literature Coordinator',
        'sleutel' => 'coordinator_literature',
        'sobriety' => '1 year continuous (suggested)',
        'ervaring' => '6 months committee service (suggested)',
        'termijn' => '1 year (suggested)',
        'taken' => [
            'Keeps adequate literature stock to meet the reasonable needs of the committee',
            'Responsible for ordering literature from the District Literature Secretary',
            'Maintains comprehensive, transparent records of literature provided and purchased',
            'Familiarises themselves with PI guidelines and C.A.\'s 12 Traditions',
            'Attends all committee meetings',
        ],
    ],
    [
        'naam' => 'PI Print Distribution Coordinator',
        'sleutel' => 'coordinator_distribution',
        'sobriety' => '1 year continuous (suggested)',
        'ervaring' => '6 months committee service (suggested)',
        'termijn' => '1 year (suggested)',
        'taken' => [
            'Agrees where and how posters/leaflets should be distributed locally (doctors, pharmacies, schools, universities, libraries, public buildings, etc.)',
            'Ensures printed material is distributed responsibly and stays up to date',
            'Prepares and delivers presentations to local services, education providers, and (non-H&amp;I) community organisations',
            'Manages regional stock, literature packages, suppliers, and pricing (in this portal: the whole Print module)',
            'Approves or rejects supplier orders',
            'Familiarises themselves with PI guidelines and C.A.\'s 12 Traditions',
        ],
    ],
    [
        'naam' => 'PI Presentation Outreach Coordinator',
        'sleutel' => 'coordinator_outreach',
        'sobriety' => '1 year continuous (suggested)',
        'ervaring' => '6 months committee service (suggested)',
        'termijn' => '1 year (suggested)',
        'taken' => [
            'Maintains contact with the professional community: healthcare providers, hospitals, clinics, social services, justice system, schools',
            'Handles and schedules outreach/presentation requests (in this portal: the whole Outreach module)',
            'Delivers or coordinates presentations to professionals ("Carrying the Message to the Professional Community")',
            'May prepare the annual "Professionals Week" campaign (third week of January)',
            'Familiarises themselves with PI guidelines and C.A.\'s 12 Traditions',
        ],
    ],
    [
        'naam' => 'PI Media Coordinator',
        'sleutel' => 'media_coordinator',
        'sobriety' => '1 year continuous (suggested)',
        'ervaring' => '6 months committee service (suggested)',
        'termijn' => '1 year (suggested)',
        'taken' => [
            'Maintains contact with local media outlets (radio, press, print, television)',
            'Distributes press releases to raise positive awareness of C.A.',
            'Places local advertising or notices to raise awareness (print ads, their online equivalents, paid search terms)',
            'Responds to local media enquiries about meetings and the fellowship',
            'Approves or rejects physical campaigns and campaign ideas (print, press, media) and is responsible for carrying them out (in this portal: PI Locations → Campaigns/Campaign Ideas, "Physical" section)',
            'Familiarises themselves with PI guidelines and C.A.\'s 12 Traditions',
        ],
    ],
    [
        'naam' => 'PI Social Media Coordinator',
        'sleutel' => 'social_media_coordinator',
        'sobriety' => '1 year continuous (suggested)',
        'ervaring' => '6 months committee service (suggested)',
        'termijn' => '1 year (suggested)',
        'taken' => [
            'Manages the committee\'s social media channels, upholding the Traditions and protecting anonymity',
            'Follows the "Right Here, Right Now" guidelines (digital communication tools for PI committees)',
            'Approves or rejects online/social-media campaigns and campaign ideas, and is responsible for carrying them out (in this portal: PI Locations → Campaigns/Campaign Ideas, "Online" section)',
            'Familiarises themselves with PI guidelines and C.A.\'s 12 Traditions',
        ],
    ],
    [
        'naam' => 'Group PI Liaison',
        'sleutel' => 'liaison',
        'sobriety' => '6 months (suggested)',
        'ervaring' => 'No prior experience required',
        'termijn' => '1 year (suggested)',
        'taken' => [
            'Attends the local PI committee meeting, collects C.A. literature/posters, and distributes them locally',
            'Acts as the link between their home group and the local PI committee',
            'Actively supports their group by regularly placing literature/posters at the meeting venue/local area',
            'Keeps group members informed of upcoming PI activities and service opportunities',
            'Familiarises themselves with PI guidelines and C.A.\'s 12 Traditions',
        ],
    ],
    [
        'naam' => 'District IT (administrator)',
        'sleutel' => 'admin',
        'sobriety' => '— (no Handbook guideline; this is a technical/support role specific to this portal)',
        'ervaring' => '—',
        'termijn' => '—',
        'taken' => [
            'Manages this PI work portal: users, roles, and access rights',
            'Has view AND edit access everywhere, as a safety net for every other role',
            'Manages the Drive Database, technical settings, and (where applicable) integrations',
            'Supports all other committee members with questions about the portal itself',
        ],
    ],
    ];
}

