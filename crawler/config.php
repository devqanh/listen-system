<?php
/**
 * Cấu hình crawler engnovate.com.
 * Cookie ở đây cần khớp với phiên đăng nhập tài khoản pro để xem được bài Pro-only.
 * Khi cookie hết hạn, đăng nhập lại trên Chrome rồi paste cookie mới vào.
 */

return [
    'base_url'   => 'https://engnovate.com',
    'list_path'  => '/dictation-shadowing-exercises/',
    'categories' => ['ielts', 'daily-life-stories', 'oxford-3000'],

    'project_root' => dirname(__DIR__),
    'data_dir'     => dirname(__DIR__) . '/data',
    // Audio đặt trong public/ để Apache phục vụ trực tiếp dưới URL /audio/<cat>/<slug>.mp3
    // (không cần stream qua PHP). DB và cache vẫn ở data/ ngoài web root.
    'audio_dir'    => dirname(__DIR__) . '/public/audio',
    'cache_dir'    => dirname(__DIR__) . '/data/cache',
    'sqlite_path'  => dirname(__DIR__) . '/data/library.sqlite',

    // Khi true: lưu HTML đã fetch vào data/cache/ để debug + tránh fetch lại trong lần chạy sau.
    'cache_html' => true,

    // Giây nghỉ giữa các request để tránh 429.
    'sleep_between_requests' => 3,
    'sleep_on_429'           => 90,
    'max_retries'            => 3,

    // Headers + cookie. Cookie từ DevTools Chrome sau khi đăng nhập.
    'headers' => [
        'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'accept-language: en-US,en;q=0.9,vi;q=0.8',
        'priority: u=0, i',
        'sec-ch-ua: "Chromium";v="146", "Not-A.Brand";v="24", "Google Chrome";v="146"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'sec-fetch-dest: document',
        'sec-fetch-mode: navigate',
        'sec-fetch-site: same-origin',
        'sec-fetch-user: ?1',
        'upgrade-insecure-requests: 1',
        'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',
    ],

    'cookie' => '_ga=GA1.1.1323277857.1777483882; saved_premium_activation_code=TTZKY0JX067H; wordpress_logged_in_942c4a947e373e5d4114cc2f266a7126=dewavn22%40gmail.com%7C1809020803%7CpE38CVVJsSPPO4sk2103roeplxUZksknYRicRzraroz%7C848ee57e57fe2fafcaa8747ef1379c24831c39c11a26a5dce224f78c541f78de; _ga_6ET5P62S5Y=GS2.1.s1777483882$o1$g1$t1777484990$j42$l0$h0',
];
