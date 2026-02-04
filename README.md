# Anti-Crawler PHP Library by CleanTalk

Simple anti-crawler library for PHP websites, with optional checks against CleanTalk block lists, allow lists, and User-Agent database.

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
        'visitor_forget_after' => 60 * 60
    ]);
```

List of settings:
db_path              string System path to the SQLite database file
api_key              string CleanTalk API key (see "Full capabilities" section above)
min_sync_interval    int    Minimum time interval between synchronizations when using default sync behavior, in seconds
max_sync_interval    int    Maximum time interval between synchronizations when using default sync behavior, in seconds
visitor_forget_after int    Time limit for storing visitor data in the library database, in seconds (decrease this if you have storage issues)
max_rows_before_sync int    Maximum number of requests stored between synchronizations when using default sync behavior
sync_by_cron         bool   Set this to true to use the cron synchronization mechanism. See `CronSync.php.example`
