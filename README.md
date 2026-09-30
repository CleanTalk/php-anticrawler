# Anti-Crawler PHP Library by CleanTalk

Free tool to block unwanted crawlers, scrapers and bad bots by User-Agent, IP block lists and allow lists. No captcha, works on any PHP site. Free self hosted alternative to Cloudflare.

**What it does**

- Blocks scrapers, SEO crawlers and scanner bots before they reach your page logic.
- Recognises 70+ known bots by User-Agent (see the list at the end) and lets you decide which of them to allow.
- Optionally checks every visitor against CleanTalk block lists and allow lists with your API key.
- Stores visitor state in SQLite or KeyDB, so it runs on shared hosting as well as on a cluster.

**Installation**

`composer require cleantalk/php-anticrawler`

**Quick start**
1) Copy `cleantalk-anticrawler.js` to your public files directory.

2) Add this to your webpage:
```
<script src="/path/to/your/public/files/cleantalk-anticrawler.js">
```

3) Add this to your PHP page logic:
```
    use Cleantalk\PHPAntiCrawler\CleanTalkAntiCrawler;

    // <...>

    $ac = new CleanTalkAntiCrawler([]);
    if ($ac->badVisitor()) {
        $ac->showAccessDeniedScreen(); // or implement your custom behavior
        exit;
    }
```

4) (Optional) Customize your block screen.
By default, crawlers receive the template defined in `cleantalk-anticrawler.html`. You can customize it as you like.

This sets you up with the basic library functionality.

**(!)** This use case will also block "good" crawlers (Googlebot, Bingbot, etc.) from visiting your page. If you do not want this behavior, see the next section.

**Full capabilities**

The library integrates with the CleanTalk database and can use its allow lists, block lists, and "good User-Agent" collection. To enable the integration, you need a CleanTalk API key for your website. Follow these steps:
1) If you do not have a CleanTalk account yet, register at https://cleantalk.org/register.
2) Once your account is created, copy your API key from the CleanTalk site.
3) Create a `Config.php` file from `Config.php.example` in the plugin directory. Fill the `API_KEY` value with your API key.
4) Ensure that your CleanTalk Anti-Spam license is active (trial or paid).

With these settings in place, the library will use CleanTalk lists and User-Agent data to make filtering more precise. You can also manage your personal allow/block lists in the website interface. Visitors' data will be sent to your CleanTalk account.

**Settings**

Configure the library by passing an array of options to the `CleanTalkAntiCrawler` constructor:

```
    $ac = new CleanTalkAntiCrawler([
        'db_path' => '/tmp/mydatabase.sqlite',
        'visitor_forget_after' => 60 * 60,
        // 'requests_backend' => 'keydb',
        // 'keydb_host' => '127.0.0.1',
        // 'keydb_port' => 6379,
    ]);
```

List of settings:

| Setting | Type | Description |
| --- | --- | --- |
| db_path | string | System path to the SQLite database file |
| api_key | string | CleanTalk API key (see "Full capabilities" section above) |
| min_sync_interval | int | Minimum time interval between synchronizations when using default sync behavior, in seconds |
| max_sync_interval | int | Maximum time interval between synchronizations when using default sync behavior, in seconds |
| visitor_forget_after | int | Time limit for storing visitor data in the library database, in seconds (decrease this if you have storage issues) |
| pending_requests_max_length | int | Maximum number of request entries retained in the KeyDB pending queue. Defaults to `100000`; older entries are trimmed away |
| max_rows_before_sync | int | Maximum number of requests stored between synchronizations when using default sync behavior |
| sync_by_cron | bool | Set this to true to use the cron synchronization mechanism. See `CronSync.php.example` |
| requests_backend | string | Backend switch for both request logs and visitor-state data: `sqlite` (default) or `keydb` |
| keydb_host | string | KeyDB host, used when `requests_backend` is `keydb` |
| keydb_port | int | KeyDB port, used when `requests_backend` is `keydb` |
| keydb_timeout | float | Connection timeout for KeyDB, in seconds |
| keydb_password | string | KeyDB password, if required |
| keydb_database | int | KeyDB database index |
| keydb_prefix | string | Prefix for KeyDB request-log keys |

**KeyDB mode**

SQLite can generate high I/O load when the traffic is high. If you see this, you can set:

```
    $ac = new CleanTalkAntiCrawler([
        'db_path' => '/tmp/mydatabase.sqlite',
        'requests_backend' => 'keydb',
        'visitor_forget_after' => 60 * 60 * 24 * 30,
        'keydb_host' => '127.0.0.1',
        'keydb_port' => 6379,
        'keydb_database' => 0,
        'keydb_prefix' => 'anticrawler',
    ]);
```

In this mode, request logs and visitor presence data are stored in KeyDB. `visitor_forget_after` is applied as visitor key TTL, and `pending_requests_max_length` caps the pending request list by count.
SQLite is still used for `kv`, `lists`, and `user_agents` tables.

**Why not a captcha**

A captcha challenges every visitor, including the real ones, and modern bots solve it anyway. This library decides on the server side, before the page is rendered: a visitor with a known bot User-Agent, a blocked IP or crawler-like behaviour gets the access denied screen, and everyone else sees the page as usual. Nothing is shown to real people, nothing has to be clicked.

**How it compares**

| Approach | Stops bots that ignore rules | Real visitors affected | Keeps working when bots change IP |
|---|---|---|---|
| `robots.txt` | No - it is a request, not a block | No | Not applicable |
| Blocking IP ranges by hand | Partly | Sometimes, when a range is shared | No |
| Captcha on every page | Partly | Yes, everyone clicks | Yes |
| **This library** | Yes | No | Yes, with CleanTalk lists updated from the cloud |

**List of supported User-Agents (bots)**

The same list is maintained in the CleanTalk help center: https://cleantalk.org/help/filter-ua


- admantx.com
- AhrefsBot
- Akamai crawler
- Alexabot
- Amazonbot
- AndiBot
- Applebot
- archive.org_bot
- AspiegelBot - HuaweiWebCatBot
- Babbar.tech (Barkrowler)
- Baidu
- Better Uptime Bot
- Bing
- Bravebot
- BrightLocalBot
- CleanTalk Uptime bot
- CloudFlare crawler
- DuckDuckGo
- FacebookBot
- FeedBurner
- Feedly Fetcher
- FreshpingBot
- Google
- Grapeshot
- GTmetrix
- GumGum-Bot
- HuaweiWebCatBot
- Hypefactors
- KomoBot
- Lighthouse
- Mail.ru
- MailChimp
- Majestic MJ12bot
- Meetedgar.com
- MirrorWeb
- Petalbot
- PhindBot
- Pingdom.com bot
- Pinterest bot
- Printful WooCommerce Integration
- proximic
- Qwant Web crawler
- Rambler bot
- RankMathApi
- RapidLoad (rapidload.io)
- Reddit
- Rogerbot - MOZ.com
- Screaming Frog SEO Spider
- Semrush
- SEOkicks-Robot
- serpstatbot
- Seznam
- ShipStation
- ShortPixel Image Optimizer
- Site24x7
- Sitechecker
- sogou spider
- StatusCake
- Sucuri Uptime Monitor
- UptimeRobot.com
- WaldoBot
- Wikipedia crawler
- WooCommerce API
- wordoftravel: Find Travel Blogs and Explore Destinations
- WP Engine Smart Plugin Manager
- WP Rocket
- WPCompress
- Yandex
- YouBot

Free standalone (cloud less), alternative to [CloudFlare BotManagement](https://www.cloudflare.com/products/bot-management/)

**Related projects by CleanTalk**

- [anti-ddos-lite](https://github.com/CleanTalk/anti-ddos-lite) - small PHP app that protects a site from HTTP flood and DDoS-like traffic.
- [php-antispam](https://github.com/CleanTalk/php-antispam) - PHP client for the CleanTalk Anti-Spam API: checks comments, registrations and form submissions.
- [wordpress-antispam](https://github.com/CleanTalk/wordpress-antispam) - the WordPress plugin with SpamFireWall, Anti-Crawler and Anti-Flood built in.
