// Usage: <script src="/path/to/public/files/cleantalk-anticrawler.js"></script>

(function() {
  try {
    var name = "js_anticrawler_passed";
    var value = "1";
    var maxAge = 31536000; // 1 year
    var cookie = name + "=" + value
               + "; max-age=" + maxAge
               + "; path=/"
               + "; samesite=Lax";
    document.cookie = cookie;
  } catch (e) {
    // swallow
  }
})();
