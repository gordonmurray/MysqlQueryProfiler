# MysqlQueryProfiler
This tool helps you to quickly profile a mysql query in a PHP 7.4+ environnement. You can also compare 2 queries.

<img width="1005" alt="Untitled" src="https://user-images.githubusercontent.com/83125994/115977227-46f33500-a576-11eb-9b85-27b1d2a6575e.png">

It shows important figures from 3 logs:
- left: status (`SHOW STATUS...`)
- right: query plan (`SHOW PROFILE...`)
- bottom: optimizer (`EXPLAIN...`)

This is a standalone page without dependency (vanilla js, css and standard php modules only).

## Why use it?
It helps you to:
- find mysql configuration issues
- improve your indexes
- improve your queries
- spot mysql limitations

## Usage
<b>Use it only in DEV and control who can access it!</b>
1) Copy the file in a secure location (with .htaccess, etc.)
2) Create a mysql user with profiling privileges.
3) Configure the tool (user, password, ip allow list, etc.)

## Usage within Docker

The following will create a PHP 7.4 container withthe mysql query profiler and also a mariadb 10.4 container

> docker-compose up -d

Open `http://localhost/mysql_query_profiler.php` in your web browser

To stop, run `docker compose down`


## Integration
You may want to profile the queries generated by your application by clicking on a link from your web pages.

1) In your main configuration file, add a constant that will allow you to turn on/off the query displaying. For example:
```php
define('MQP_PROFILE_QUERIES', true);
```

2) Copy-paste-adapt this code in a method where all your queries go through:
```php
if (MQP_PROFILE_QUERIES) {
  echo '<div style="border:1px solid #ff9966;padding:5px;margin:5px">';
  echo '<a href="/mysql_query_profiler.php?query=' . urlencode($query) . '" target="mqp">';
  echo htmlspecialchars($query);
  echo '</a>';
  echo '</div>';
}
```
