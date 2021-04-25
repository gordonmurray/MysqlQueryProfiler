<?php

//------------------------------------------------------------------------------
// Warning
//
// !! USE THIS TOOL IN DEV ONLY
// !! SECURE ITS ACCESS
//------------------------------------------------------------------------------

declare(strict_types=1);

//------------------------------------------------------------------------------
// Configuration
//------------------------------------------------------------------------------

define('DATABASE_HOST', '');
define('DATABASE_USER', '');
define('DATABASE_PASSWORD', '');
define('DATABASE_NAME', '');

/**
 * Sometimes the data must be cached by the engine.
 * A warm up of 3 runs is the best compromise
 */
define('MQP_WARM_UP_RUNS', 3);

/**
 * Should be enough when working with heavy queries
 */
define('MQP_MAX_EXECUTION_TIME', '600');

/**
 * What is the website/database charset
 */
define('MQP_CHARSET', 'iso-8859-1');

/**
 * If you want to filter the acces by ip, add the allowed ips here.
 * Filtering is off if empty.
 */
define('MQP_ALLOWED_IPS', []);

//------------------------------------------------------------------------------
// Library
//------------------------------------------------------------------------------

abstract class Profiler
{

	protected Mysqli $database;
	protected string $query = '';
	protected array $results = [];

	public function __construct(Mysqli $database)
	{
		$this->database = $database;
	}

	abstract public function profile(string $query): array;

	public function init(string $query): void
	{
		$this->query = $query;
		$this->results = [];
	}

}

final class StatusProfiler extends Profiler
{

	public int $warmUpRuns;

	public function __construct(Mysqli $database, int $warmUpRuns)
	{
		$this->database = $database;
		$this->warmUpRuns = $warmUpRuns;
	}

	public function profile(string $query): array
	{
		$this->init($query);

		$this->warmUpServer();
		$this->profileQuery();

		return $this->results;
	}

	private function warmUpServer(): void
	{
		for ($i = 1; $i <= $this->warmUpRuns; $i++) {
			$this->database->query($this->query);
		}
	}

	private function profileQuery(): void
	{
		$statusStart = $this->getStatus();
		$this->database->query($this->query);
		$statusEnd = $this->getStatus();

		$this->calculateStatusDifference($statusStart, $statusEnd);
	}

	private function getStatus(): array
	{
		$results = $this->database->query('SHOW STATUS');

		$rows = [];

		while ($row = $results->fetch_assoc()) {
			$rows[$row['Variable_name']] = $row['Value'];
		}

		return $rows;
	}

	private function calculateStatusDifference(array $start, array $end): void
	{
		/**
		 * All values are basic counters, let's calculate the difference
		 */
		foreach ($start as $name => $value) {
			if (is_numeric($end[$name]) && is_numeric($value)) {
				$this->results[$name] = $end[$name] - $value;
			}
		}

		/**
		 * Last Query Cost is of course an exception.
		 */
		$this->results['Last_query_cost'] = $end['Last_query_cost'];
	}

}

final class ExplainProfiler extends Profiler
{

	public function __construct(Mysqli $database)
	{
		$this->database = $database;
	}

	public function profile(string $query): array
	{
		$this->init($query);

		$this->explain($query);

		return $this->results;
	}

	private function explain(string $query): void
	{
		$results = $this->database->query('EXPLAIN ' . $query);

		if ($results) {
			while ($row = $results->fetch_assoc()) {
				$this->results[] = $row;
			}
		}
	}

}

final class TraceProfiler extends Profiler
{

	public function __construct(Mysqli $database)
	{
		$this->database = $database;
	}

	public function profile(string $query): array
	{
		$this->init($query);

		$this->startProfiling();
		$this->runQuery();
		$this->retrieveTrace();
		$this->stopProfiling();

		return $this->results;
	}

	private function startProfiling(): void
	{
		$this->database->query('SET profiling = 1');
	}

	private function stopProfiling(): void
	{
		$this->database->query('SET profiling = 0');
	}

	private function runQuery(): void
	{
		$this->database->query($this->query);
	}

	/**
	 * Retrieve the trace for the query run
	 * https://dev.mysql.com/doc/refman/5.7/en/information-schema-profiling-table.html
	 */
	private function retrieveTrace(): void
	{
		$results = $this->database->query('SELECT * FROM INFORMATION_SCHEMA.PROFILING WHERE QUERY_ID=1 ORDER BY SEQ');

		while ($row = $results->fetch_assoc()) {
			$this->results[] = $row;
		}
	}

}

//------------------------------------------------------------------------------
// GUI
//------------------------------------------------------------------------------

interface Renderable
{
	public function render(): string;
}

final class Form implements Renderable
{

	private string $query;
	private string $queryCompare;

	public function __construct(string $query, string $queryCompare)
	{
		$this->query = $query;
		$this->queryCompare = $queryCompare;
	}

	public function render(): string
	{
		$html = '<script>';
		$html .= 'function permute(a,b){c=a.value;a.value=b.value;b.value=c;}';
		$html .= '</script>';

		$html .= '<div id="form">';
		$html .= '<form method="get" action="">';
		$html .= '<textarea id="query" name="query" accesskey="a" rows="6" cols="70" title="Query 1" >' . htmlentities(trim($this->query)) . '</textarea>';
		$html .= '&nbsp;<input type="button" value="<=>" onClick="permute(query,queryCompare)">&nbsp;';
		$html .= '<textarea id="queryCompare" name="queryCompare" accesskey="b" rows="6" cols="70" title="Query 2">' . htmlentities(trim($this->queryCompare)) . '</textarea>';
		$html .= '&nbsp; <input type="submit" value="Profile !">';
		$html .= ' &nbsp;&nbsp;&nbsp;&nbsp;[<a href="' . $_SERVER['PHP_SELF'] . '" onclick="query.value=\'\';queryCompare.value=\'\';return false;">Delete all</a>]';
		$html .= '</form>';
		$html .= '</div>';

		return $html;
	}

}

final class StatusReport implements Renderable
{

	private array $statusResults;

	private const IMPORTANT_STATUS_VALUES = [
		'Last_query_cost',
	];
	private const URL_MYSQL_DOC_STATUS_VARIABLES = 'https://dev.mysql.com/doc/refman/en/server-status-variables.html#statvar_';

	public function __construct(array $statusResults)
	{
		$this->statusResults = $statusResults;
	}

	public function render(): string
	{
		if (!$this->statusResults) {
			return '';
		}

		$compareQueries = isset($this->statusResults[1]) ? true : false;

		$results = [];
		foreach ($this->statusResults[0] as $name => $value) {
			$results[$name][0] = $value;
		}
		if ($compareQueries) {
			foreach ($this->statusResults[1] as $name => $value) {
				$results[$name][1] = $value;
			}
		}
		ksort($results);

		$html = '<div style="float:left;padding:0 20px 10px 0">';
		$html .= '<table summary="">';
		$html .= '<tr><th>&nbsp;<th class="c" style="width:80px">Query 1' . ($compareQueries ? '<th class="c" style="width:80px">Query 2' : '');

		foreach ($results as $name => $values) {
			if (isset($values[0]) && isset($values[1]) && $values[0] == $values[1] || !isset($values[1])) {
				if ($values[0] != 0) {
					$html .= '<tr' . (in_array($name, self::IMPORTANT_STATUS_VALUES) ? ' class="important"' : '') . ' onmouseover="s' . $name . '.style.visibility=\'visible\'" onmouseout="s' . $name . '.style.visibility=\'hidden\'">';
					$html .= '<td>' . $name . ' <a id="s' . $name . '" href="' . self::URL_MYSQL_DOC_STATUS_VARIABLES . $name . '" target="_blank" style="visibility:hidden">[?]</a>';
					$html .= '<td class="result equal"' . ($compareQueries ? ' colspan="2"' : '') . '>' . number_format((int) $values[0], 0, '', '');
				}
			} else {
				if ($values[0] != 0 || $values[1] != 0) {
					$html .= '<tr' . (in_array($name, self::IMPORTANT_STATUS_VALUES) ? ' class="important"' : '') . ' onmouseover="s' . $name . '.style.visibility=\'visible\'" onmouseout="s' . $name . '.style.visibility=\'hidden\'">';
					$html .= '<td>' . $name . ' <a id="s' . $name . '" href="' . self::URL_MYSQL_DOC_STATUS_VARIABLES . $name . '" target="_blank" style="visibility:hidden">[?]</a>';
					$html .= '<td class="result' . ($compareQueries && $values[0] < $values[1] ? ' better' : '') . '">' . (isset($values[0]) ? number_format((int) $values[0], 0, '', '') : '0');
					$html .= '<td class="result' . ($compareQueries && $values[0] > $values[1] ? ' better' : '') . '">' . (isset($values[1]) ? number_format((int) $values[1], 0, '', '') : '0');
				}
			}
		}
		$html .= '</table></div>';

		return $html;
	}

}

final class TraceReport implements Renderable
{

	private array $traceResults;

	public function __construct(array $traceResults)
	{
		$this->traceResults = $traceResults;
	}

	public function render(): string
	{
		if (!$this->traceResults) {
			return '';
		}

		$compareQueries = isset($this->traceResults[1]) ? true : false;

		$queryProfileSteps = [];
		foreach ($this->traceResults[0] as $step) {
			$queryProfileSteps[0][trim(strtolower($step['STATE']))] = true;
		}
		if ($compareQueries) {
			foreach ($this->traceResults[1] as $step) {
				$queryProfileSteps[1][trim(strtolower($step['STATE']))] = true;
			}
		}
		$queryId = 1;
		$precision = 3;

		$html = '';

		foreach ($this->traceResults as $queryProfile) {
			$html .= '<div style="float:left;padding:0 20px 10px 0">';
			$html .= '<table summary=""><tr><th>Query ' . $queryId . '<th>Duration';
			$totalDuration = 0;
			foreach ($queryProfile as $tmpProfile) {
				$class = ($compareQueries && (!isset($queryProfileSteps[0][trim(strtolower($tmpProfile['STATE']))]) || !isset($queryProfileSteps[1][trim(strtolower($tmpProfile['STATE']))])) ? ' different' : '');
				$html .= '<tr><td class="' . $class . '">' . ucfirst($tmpProfile['STATE']);
				$html .= '<td class="result' . $class . '">' . number_format($tmpProfile['DURATION'] * 1000, $precision);
				$totalDuration += $tmpProfile['DURATION'] * 1000;
			}
			$html .= '<tr><td><b>Total</b>';
			$html .= '<td class="result">' . number_format($totalDuration, $precision);
			$html .= '</table>';
			$html .= '</div>';
			$queryId++;
		}

		return $html;
	}

}

final class ExplainReport implements Renderable
{

	private array $explainResults;

	public function __construct(array $explainResults)
	{
		$this->explainResults = $explainResults;
	}

	public function render(): string
	{
		if (!$this->explainResults) {
			return '';
		}

		$queryId = 1;

		$html = '<table id="explain" summary="">';
		$html .= '<tr><th>id<th>select_type<th>Table<th>Type<th>Possible keys<th>Key<th class="r">Key len<th>Ref<th class="r">Rows<th class="r">Filtered<th>Extra';
		foreach ($this->explainResults as $queryExplained) {
			$html .= '<tr><th colspan="11" class="separator">Query ' . $queryId;
			foreach ($queryExplained as $data) {
				$html .= '<tr>';
				$html .= '<td class="r">' . $data['id'];
				$html .= '<td>' . $data['select_type'];
				$html .= '<td>' . $data['table'];
				$html .= '<td>' . $data['type'];
				$html .= '<td>' . str_replace(',', ', ', $data['possible_keys']);
				$html .= '<td>' . $data['key'];
				$html .= '<td class="r">' . $data['key_len'];
				$html .= '<td>' . $data['ref'];
				$html .= '<td class="r">' . $data['rows'];
				$html .= '<td class="r">' . number_format((float) $data['filtered']) . '%';
				$html .= '<td>' . $data['Extra'];
			}
			$queryId++;
		}
		$html .= '</table>';

		return $html;
	}

}

final class Page implements Renderable
{

	private Renderable $form;
	private Renderable $displayQueryStatus;
	private Renderable $displayTracer;
	private Renderable $displayExplainer;

	public function __construct(Renderable $form, Renderable $displayQueryStatus, Renderable $displayTracer, Renderable $displayExplainer)
	{
		$this->form = $form;
		$this->displayQueryStatus = $displayQueryStatus;
		$this->displayTracer = $displayTracer;
		$this->displayExplainer = $displayExplainer;
	}

	public function sendHttpHeader()
	{
		// https://www.keycdn.com/support/cache-control/
		// HTTP 1.1
		header("Cache-Control: no-cache, no-store, max-age=0, private, post-check=0, pre-check=0, must-revalidate");

		// https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
		header("Content-Type: text/html; charset=" . MQP_CHARSET);

		// Proxies
		header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

		// HTTP 1.0
		header("Pragma: no-cache");

		// https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Strict-Transport-Security
		header("Strict-Transport-Security: max-age=2592000; includeSubDomains;");

		// https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Content-Type-Options
		header("X-Content-Type-Options: nosniff");

		// https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Frame-Options
		header("X-Frame-Options: SAMEORIGIN");
	}

	public function render(): string
	{
		$html = '<!DOCTYPE html>';
		$html .= '<head><title>MySQL query profiler 1.0</title>';
		$html .= '<meta http-equiv="Content-Type" content="text/html; charset=' . MQP_CHARSET . '">';

		$html .= '<style type="text/css"><!-- ';
		$html .= 'body, div, textarea, th, td {font-family:"Courier New", Courier, monospace;font-size:12px} ';
		$html .= 'body {background-color:#fff} ';
		$html .= 'a {text-decoration:none} ';
		$html .= 'form {margin:0} ';
		$html .= 'textarea{padding:5px} ';
		$html .= '.resultset {margin:0 0 15px 0; clear:both} ';
		$html .= 'table {border: 1px solid #ddd; border-collapse:collapse} ';
		$html .= 'th,td {border: 1px solid #ddd;padding:5px} ';
		$html .= '#explain{width:100%} ';
		$html .= '.result {text-align:right} ';
		$html .= '.equal {text-align:center} ';
		$html .= '.different, #form, .separator {background-color:#e9edf5} ';
		$html .= '.better {background-color:#c9edcc} ';
		$html .= '.important td {font-weight: bold} ';
		$html .= '#form {padding:10px;margin:0 0 15px 0} ';
		$html .= 'th {text-align:left} ';
		$html .= '.c {text-align:center} ';
		$html .= '.r {text-align:right} ';
		$html .= '//--> </style> ';

		$html .= '</head><body>';

		$html .= $this->form->render();

		$html .= '<div class="resultset">';
		$html .= $this->displayQueryStatus->render();
		$html .= $this->displayTracer->render();
		$html .= '</div>';

		$html .= '<div class="resultset">';
		$html .= $this->displayExplainer->render();
		$html .= '</div>';

		$html .= '</body></html>';

		return $html;
	}

}

//------------------------------------------------------------------------------
// Application
//------------------------------------------------------------------------------

ini_set("max_execution_time", MQP_MAX_EXECUTION_TIME);

if (count(MQP_ALLOWED_IPS) > 0 && !in_array($_SERVER['REMOTE_ADDR'], MQP_ALLOWED_IPS)) {
	http_response_code(403);
	echo 'Access denied.';
	exit;
}

$query = $_GET['query'] ?? '';
$queryCompare = $_GET['queryCompare'] ?? '';

if (empty($query) && $queryCompare) {
	$query = $queryCompare;
	$queryCompare = '';
}

$compareQueries = !empty($queryCompare) ? true : false;

$statusResults = $traceResults = $explainResults = [];

if (!empty($query)) {
	$database = new Mysqli(DATABASE_HOST, DATABASE_USER, DATABASE_PASSWORD, DATABASE_NAME);
	$statusResults[] = ((new StatusProfiler($database, MQP_WARM_UP_RUNS))->profile($query));
	$traceResults[] = ((new TraceProfiler($database))->profile($query));
	$explainResults[] = (new ExplainProfiler($database))->profile($query);

	/**
	 * We need a new connection to reset the query ID in INFORMATION_SCHEMA.PROFILING.
	 * Other solution would be to close and reopen the connection.
	 */
	if ($compareQueries) {
		$database = new Mysqli(DATABASE_HOST, DATABASE_USER, DATABASE_PASSWORD, DATABASE_NAME);
		$statusResults[] = ((new StatusProfiler($database, MQP_WARM_UP_RUNS))->profile($queryCompare));
		$traceResults[] = ((new TraceProfiler($database))->profile($queryCompare));
		$explainResults[] = (new ExplainProfiler($database))->profile($queryCompare);
	}
}

$page = new Page(
		new Form($query, $queryCompare),
		new StatusReport($statusResults),
		new TraceReport($traceResults),
		new ExplainReport($explainResults)
);

$page->sendHttpHeader();
echo $page->render();
