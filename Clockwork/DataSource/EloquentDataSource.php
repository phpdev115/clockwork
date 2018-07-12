<?php namespace Clockwork\DataSource;

use Clockwork\Helpers\Serializer;
use Clockwork\Helpers\StackTrace;
use Clockwork\Request\Request;
use Clockwork\Support\Laravel\Eloquent\ResolveModelScope;
use Clockwork\Support\Laravel\Eloquent\ResolveModelLegacyScope;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;

/**
 * Data source for Eloquent (Laravel ORM), provides database queries
 */
class EloquentDataSource extends DataSource
{
	/**
	 * Database manager
	 */
	protected $databaseManager;

	/**
	 * Internal array where queries are stored
	 */
	protected $queries = [];

	/**
	 * Model name to associate with the next executed query, used to map queries to models
	 */
	public $nextQueryModel;

	/**
	 * Create a new data source instance, takes a database manager and an event dispatcher as arguments
	 */
	public function __construct(ConnectionResolverInterface $databaseManager, EventDispatcher $eventDispatcher)
	{
		$this->databaseManager = $databaseManager;
		$this->eventDispatcher = $eventDispatcher;
	}

	/**
	 * Start listening to eloquent queries
	 */
	public function listenToEvents()
	{
		if ($scope = $this->getModelResolvingScope()) {
			$this->eventDispatcher->listen('eloquent.booted: *', function ($model, $data = null) use ($scope) {
				if (is_string($model) && is_array($data)) { // Laravel 5.4 wildcard event
					$model = reset($data);
				}

				$model->addGlobalScope($scope);
			});
		}

		if (class_exists(\Illuminate\Database\Events\QueryExecuted::class)) {
			// Laravel 5.2 and up
			$this->eventDispatcher->listen(\Illuminate\Database\Events\QueryExecuted::class, [ $this, 'registerQuery' ]);
		} else {
			// Laravel 5.0 to 5.1
			$this->eventDispatcher->listen('illuminate.query', [ $this, 'registerLegacyQuery' ]);
		}
	}

	/**
	 * Log the query into the internal store
	 */
	public function registerQuery($event)
	{
		$trace = StackTrace::get()->resolveViewName();
		$caller = $trace->firstNonVendor([ 'itsgoingd', 'laravel', 'illuminate' ]);

		$this->queries[] = [
			'query'      => $event->sql,
			'bindings'   => $event->bindings,
			'time'       => $event->time,
			'connection' => $event->connectionName,
			'file'       => $caller->shortPath,
			'line'       => $caller->line,
			'trace'      => $this->collectStackTraces ? (new Serializer)->trace($trace->framesBefore($caller)) : null,
			'model'      => $this->nextQueryModel
		];

		$this->nextQueryModel = null;
	}

	/**
	 * Log a legacy (pre Laravel 5.2) query into the internal store
	 */
	public function registerLegacyQuery($sql, $bindings, $time, $connection)
	{
		return $this->registerQuery((object) [
			'sql'            => $sql,
			'bindings'       => $bindings,
			'time'           => $time,
			'connectionName' => $connection
		]);
	}

	/**
	 * Adds ran database queries to the request
	 */
	public function resolve(Request $request)
	{
		$request->databaseQueries = array_merge($request->databaseQueries, $this->getDatabaseQueries());

		return $request;
	}

	/**
	 * Takes a query, an array of bindings and the connection as arguments, returns runnable query with upper-cased keywords
	 */
	protected function createRunnableQuery($query, $bindings, $connection)
	{
		// add bindings to query
		$bindings = $this->databaseManager->connection($connection)->prepareBindings($bindings);

		foreach ($bindings as $binding) {
			$binding = $this->quoteBinding($binding, $connection);

			// convert binary bindings to hexadecimal representation
			if (! preg_match('//u', $binding)) $binding = '0x' . bin2hex($binding);

			// escape backslashes in the binding (preg_replace requires to do so)
			$binding = str_replace('\\', '\\\\', $binding);

			$query = preg_replace('/\?/', $binding, $query, 1);
		}

		// highlight keywords
		$keywords = [
			'select', 'insert', 'update', 'delete', 'where', 'from', 'limit', 'is', 'null', 'having', 'group by',
			'order by', 'asc', 'desc'
		];
		$regexp = '/\b' . implode('\b|\b', $keywords) . '\b/i';

		$query = preg_replace_callback($regexp, function ($match) { return strtoupper($match[0]); }, $query);

		return $query;
	}

	/**
	 * Takes a query binding and a connection name, returns a quoted binding value
	 */
	protected function quoteBinding($binding, $connection)
	{
		$connection = $this->databaseManager->connection($connection);

		if ($connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'odbc') {
			// PDO_ODBC driver doesn't support the quote method, apply simple MSSQL style quoting instead
			return "'" . str_replace("'", "''", $binding) . "'";
		}

		return $connection->getPdo()->quote($binding);
	}

	/**
	 * Returns an array of runnable queries and their durations from the internal array
	 */
	protected function getDatabaseQueries()
	{
		return array_map(function ($query) {
			return [
				'query'      => $this->createRunnableQuery($query['query'], $query['bindings'], $query['connection']),
				'duration'   => $query['time'],
				'connection' => $query['connection'],
				'file'       => $query['file'],
				'line'       => $query['line'],
				'trace'      => $query['trace'],
				'model'      => $query['model']
			];
		}, $this->queries);
	}

	/**
	 * Returns model resolving scope for the installed Laravel version
	 */
	protected function getModelResolvingScope()
	{
		if (interface_exists(\Illuminate\Database\Eloquent\ScopeInterface::class)) {
			// Laravel 5.0 to 5.1
			return new ResolveModelLegacyScope($this);
		}

		return new ResolveModelScope($this);
	}
}
