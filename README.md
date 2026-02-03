# Anti-Crawler PHP library by CleanTalk

Simple anti-crawler library for PHP websites with optional capabilites of checking the requests by CleanTalk's Block Lists, Allow Lists and User-Agent database.

**Installation**

`composer install cleantalk/php-anticrawler`

**Quick start**
1) Copy `cleantalk-anticrawler.js` file to your public files directory.

2) Add this to your webpage: `<script src="/path/to/your/public/files/cleantalk-anticrawler.js">` \

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

4) (optional) Customize your block screen \
By default, crawlers will get the template defined in `cleantalk-anticrawler.html` file. You can customize it as you like.

This sets you up for the basic library functionality.

**(!)** This usecase will also prevent "good" crawlers (Googlebot, Bingbot etc.) from visiting your page. If this behavior is unwanted for you, see the next section.

**Full capabilites**

The library has integration with CleanTalk database and can use its allow lists and block lists, as well as its "good User-Agent" collection. To enable the integration, you need to get the CleanTalk API Key for your website. Follow these steps:
1) If you don't have a CleanTalk account yet, follow this URL and create one: https://cleantalk.org/register.
2) When you have a CleanTalk account, grab your API key from the website.
3) Create a `Config.php` file from the `Config.php.example` one in the plugin directory. Fill the `API_KEY` value with your API Key.
4) Ensure that your CleanTalk Anti-Spam license is active (either a trial or paid one).

With all things set, the library will start using CleanTalk lists and User-Agents data making the filtration more precise. You can also fill your personal allow/block lists in the website interface. The data on blocked visitors will also be sent to your CleanTalk account.

**Settings**

The library can be configured by passing an array of options to the CleanTalkAntiCrawler constructor:

```
    $ac = new CleanTalkAntiCrawler([
        'db_path' => '/tmp/mydatabase.sqlite',
        'visitor_forget_after' => 60 * 60
    ]);
```

List of settings:
db_path              string System path to SQLite database file
api_key              string CleanTalk API Key (see "Full capabilities" section above)
min_sync_interval    int    Minimal time interval between synchronizations if using default sync behavior, in seconds
max_sync_interval    int    Maximal time interval between synchronizations if using default sync behavior, in seconds
visitor_forget_after int    Time limit for which we store visitors data in the library database, in seconds (decrease this if having storage issues)
max_rows_before_sync int    Maximal amount of requests stored between synchronizations if using default sync behavior
sync_by_cron         bool   Set this to true if you want to use cron synchronization mechanism. See the CronSync.php.example file
