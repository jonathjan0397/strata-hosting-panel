<?php

/**
 * App Installer catalog.
 *
 * 'automated' => true  — fully automated via agent CLI tools (WP-CLI etc.)
 * 'automated' => false — assisted: agent downloads + extracts + creates DB,
 *                        user completes install via web browser.
 */
return [

    'wordpress' => [
        'name'        => 'WordPress',
        'tagline'     => 'The world\'s most popular CMS. Powers 40%+ of the web.',
        'category'    => 'cms',
        'icon'        => 'wordpress',
        'color'       => 'blue',
        'automated'   => true,
        'requires_db' => true,
        'version_api' => 'https://api.wordpress.org/core/version-check/1.7/',
        'download_url'=> 'https://wordpress.org/latest.zip',
        'features'    => ['Blogs', 'Business sites', 'WooCommerce', 'Thousands of plugins'],
    ],

    'joomla' => [
        'name'        => 'Joomla',
        'tagline'     => 'Flexible CMS for complex community and e-commerce sites.',
        'category'    => 'cms',
        'icon'        => 'joomla',
        'color'       => 'orange',
        'automated'   => false,
        'requires_db' => true,
        'version_api' => 'https://downloads.joomla.org/api/v1/latest/cms',
        'download_url'=> 'https://downloads.joomla.org/cms/joomla5/5-3-0/Joomla_5.3.0-Stable-Full_Package.zip',
        'features'    => ['Multi-language', 'ACL', 'Extensions marketplace'],
    ],

    'drupal' => [
        'name'        => 'Drupal',
        'tagline'     => 'Enterprise-grade CMS for complex, high-traffic sites.',
        'category'    => 'cms',
        'icon'        => 'drupal',
        'color'       => 'indigo',
        'automated'   => false,
        'requires_db' => true,
        'version_api' => 'https://updates.drupal.org/release-history/drupal/current',
        'download_url'=> 'https://www.drupal.org/download-latest/tar.gz',
        'features'    => ['Content workflows', 'API-first', 'High scalability'],
    ],

    'piwigo' => [
        'name'        => 'Piwigo',
        'tagline'     => 'Open-source photo gallery — manage and share your images.',
        'category'    => 'gallery',
        'icon'        => 'piwigo',
        'color'       => 'pink',
        'automated'   => false,
        'requires_db' => true,
        'version_api' => 'https://piwigo.org/download/dlcounter.php?code=latest',
        'download_url'=> 'https://piwigo.org/download/dlcounter.php?code=latest',
        'features'    => ['Albums', 'Batch upload', 'Tags', 'Plugins'],
    ],

    'phpbb' => [
        'name'        => 'phpBB',
        'tagline'     => 'The leading open-source bulletin board and forum software.',
        'category'    => 'forum',
        'icon'        => 'phpbb',
        'color'       => 'emerald',
        'automated'   => false,
        'requires_db' => true,
        'version_api' => 'https://www.phpbb.com/api/update_check/phpbb/3',
        'download_url'=> 'https://download.phpbb.com/pub/release/3.3/3.3.12/phpBB-3.3.12.zip',
        'features'    => ['Sub-forums', 'Permissions', 'Extensions', 'BBCode'],
    ],

];
