<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;  // This is the correct import
use Illuminate\Support\Facades\Log;


class DatabaseController extends Controller
{
    // Fetch and display all databases
    public function index()
    {
        // Fetch database names from General_BD_TABLES
        $databases = DB::table('General_BD_TABLES')->pluck('db_name', 'id_bd');

        // Return the view and pass the databases
        return view('TP.index', compact('databases'));
    }

    // Fetch and display tables based on the clicked database
    public function getTables($db_id)
    {
        try {
            $tables = DB::table('General_TABLE_TABLES')
                ->where('db_id', $db_id)
                ->orderBy('timestamp_insert', 'desc')
                ->pluck('table_name');

            if ($tables->isEmpty()) {
                return response()->json(['message' => 'No tables found for this database.'], 404);
            }

            return response()->json(['tables' => $tables]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch tables. ' . $e->getMessage()], 500);
        }
    }
    public function runQuery(Request $request)
    {
        $userQuery = trim($request->input('sql_query'));

        try {
            // Détecter le type de la requête
            $queryType = $this->getQueryType($userQuery);

            // Générer la requête interne en fonction du type
            $internalQuery = $this->generateInternalQuery($queryType, $userQuery);

            // Exécuter la requête SQL générée
            if (strtoupper($queryType) === 'DROP_DATABASE') {
                // Exécuter les requêtes DELETE pour supprimer la base de données et ses tables associées dans une transaction
                DB::transaction(function () use ($internalQuery) {
                    DB::statement($internalQuery['sql'], $internalQuery['bindings']);
                });

                // Une fois la base de données supprimée, récupérer la liste mise à jour des bases de données
                $databases = DB::select('SHOW DATABASES');
            } else {
                // Si ce n'est pas une requête SELECT (comme INSERT, DELETE, etc.), utilisez DB::statement
                $result = DB::statement($internalQuery['sql'], $internalQuery['bindings']);
                $databases = DB::select('SHOW DATABASES'); // Récupérer la liste des bases de données après une autre requête
            }

            // Retourner la réponse JSON avec les résultats et la liste des bases de données
            return response()->json([
                'success' => true,
                'message' => 'Query executed successfully.',
                'internal_query' => $internalQuery['sql'],
                'databases' => $databases, // Retourner la liste mise à jour des bases de données
                'result' => $result ?? null
            ]);
        } catch (Exception $e) {
            // Log l'exception complète pour mieux comprendre l'erreur
            Log::error('Query execution failed: ' . $e->getMessage());

            // Afficher l'erreur détaillée dans la réponse JSON
            return response()->json([
                'success' => false,
                'message' => 'Failed to execute query: ' . $e->getMessage(),
                'error_details' => $e->getTraceAsString() // Afficher les détails de l'exception
            ], 500);
        }
    }





    // Fonction pour détecter le type de la requête
    private function getQueryType($query)
    {
        if (preg_match('/^\s*CREATE\s+DATABASE/i', $query)) {
            return 'CREATE_DATABASE';
        } elseif (preg_match('/^\s*SHOW\s+DATABASES/i', $query)) {
            return 'SHOW_DATABASES';
        } elseif (preg_match('/^\s*CREATE\s+TABLE/i', $query)) {
            return 'CREATE_TABLE';
        } elseif (preg_match('/^\s*SHOW\s+TABLES/i', $query)) {
            return 'SHOW_TABLES';
        } elseif (preg_match('/^\s*ALTER\s+TABLE/i', $query)) {
            return 'ALTER_TABLE';
        } elseif (preg_match('/^\s*DROP\s+TABLE/i', $query)) {
            return 'DROP_TABLE';
        } elseif (preg_match('/^\s*INSERT\s+INTO/i', $query)) {
            return 'INSERT_VALUES';
        } elseif (preg_match('/^\s*DELETE\s+FROM/i', $query)) {
            return 'DELETE_VALUES';
        } elseif (preg_match('/^\s*UPDATE\s+/i', $query)) {
            return 'UPDATE_VALUES';
        } elseif (preg_match('/^\s*DROP\s+DATABASE/i', $query)) {
            return 'DROP_DATABASE';
        }
        throw new Exception('Unsupported query type.');
    }

    // Fonction pour générer la requête interne basée sur le type de requête
    private function generateInternalQuery($type, $query)
    {
        switch ($type) {
            case 'CREATE_DATABASE':
                return $this->generateCreateDatabaseQuery($query);

            case 'SHOW_DATABASES':
                return [
                    'sql' => 'SELECT db_name FROM General_BD_Tables ORDER BY timestamp_insert DESC',
                    'bindings' => []
                ];

            case 'CREATE_TABLE':
                return $this->generateCreateTableQuery($query);

            case 'SHOW_TABLES':
                return $this->generateShowTablesQuery($query);

            case 'INSERT_VALUES':
                return $this->generateInsertValuesQuery($query);

            case 'DELETE_VALUES':
                return $this->generateDeleteValuesQuery($query);

            case 'UPDATE_VALUES':
                return $this->generateUpdateValuesQuery($query);

            case 'DROP_DATABASE':
                return $this->generateDropDatabaseQuery($query);

                /*case 'DROP_TABLE':
                return $this->generateDropTableQuery($query);
*/
            case 'ALTER_TABLE':
                return $this->generateAlterTableQuery($query);

            default:
                throw new Exception('Unsupported query type.');
        }
    }

    // Gérer la requête CREATE DATABASE
    private function generateCreateDatabaseQuery($query)
    {
        if (preg_match('/CREATE\s+DATABASE\s+([a-zA-Z0-9_]+)/i', $query, $matches)) {
            $dbName = $matches[1];
            return [
                'sql' => 'INSERT INTO General_BD_Tables (db_name, timestamp_insert) VALUES (?, NOW())',
                'bindings' => [$dbName]
            ];
        }
        throw new Exception('Invalid CREATE DATABASE query.');
    }

    // Gérer la requête CREATE TABLE
    private function generateCreateTableQuery($query)
    {
        if (preg_match('/CREATE\s+TABLE\s+([a-zA-Z0-9_]+)\s*\((.+)\)/i', $query, $matches)) {
            $tableName = $matches[1];
            $dbName = $this->extractDatabaseNameFromQuery($query);
            return [
                'sql' => 'INSERT INTO General_TABLE_Tables (db_id, table_name, timestamp_insert) VALUES ((SELECT db_id FROM General_BD_Tables WHERE db_name = ?), ?, NOW())',
                'bindings' => [$dbName, $tableName]
            ];
        }
        throw new Exception('Invalid CREATE TABLE query.');
    }


    private function extractDatabaseNameFromQuery($query)
    {
        // Rechercher une clause `FROM` dans la requête (par exemple, SHOW TABLES FROM <database>)
        if (preg_match('/FROM\s+([a-zA-Z0-9_]+)/i', $query, $matches)) {
            return $matches[1];  // Le nom de la base de données extrait
        }

        // Si le nom de la base de données n'est pas trouvé, lever une exception
        throw new Exception('Database name not found in query.');
    }


    // Gérer la requête SHOW TABLES
    private function generateShowTablesQuery($query)
    {
        if (preg_match('/SHOW\s+TABLES\s+FROM\s+([a-zA-Z0-9_]+)/i', $query, $matches)) {
            $dbName = $matches[1];
            return [
                'sql' => 'SELECT table_name FROM General_TABLE_Tables WHERE db_id = (SELECT db_id FROM General_BD_Tables WHERE db_name = ?) ORDER BY timestamp_insert DESC',
                'bindings' => [$dbName]
            ];
        }
        throw new Exception('Invalid SHOW TABLES query.');
    }

    // Gérer la requête INSERT INTO
    private function generateInsertValuesQuery($query)
    {
        if (preg_match('/INSERT\s+INTO\s+([a-zA-Z0-9_]+)\s*\((.+)\)\s*VALUES\s*\((.+)\)/i', $query, $matches)) {
            $tableName = $matches[1];
            $attributes = explode(',', $matches[2]);
            $values = explode(',', $matches[3]);
            return [
                'sql' => 'INSERT INTO General_VALUE_Tables (table_id, attribute_values, timestamp_insert) VALUES ((SELECT table_id FROM General_TABLE_Tables WHERE table_name = ?), ?, NOW())',
                'bindings' => [$tableName, json_encode(array_combine($attributes, $values))]
            ];
        }
        throw new Exception('Invalid INSERT INTO query.');
    }

    // Gérer la requête DELETE
    private function generateDeleteValuesQuery($query)
    {
        if (preg_match('/DELETE\s+FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+(.+)/i', $query, $matches)) {
            $tableName = $matches[1];
            $conditions = $matches[2];
            return [
                'sql' => 'DELETE FROM General_VALUE_Tables WHERE table_id = (SELECT table_id FROM General_TABLE_Tables WHERE table_name = ?) AND ' . $conditions,
                'bindings' => [$tableName]
            ];
        }
        throw new Exception('Invalid DELETE query.');
    }

    // Gérer la requête UPDATE
    private function generateUpdateValuesQuery($query)
    {
        if (preg_match('/UPDATE\s+([a-zA-Z0-9_]+)\s+SET\s+(.+)\s+WHERE\s+(.+)/i', $query, $matches)) {
            $tableName = $matches[1];
            $setClause = $matches[2];
            $whereClause = $matches[3];
            return [
                'sql' => "UPDATE General_VALUE_Tables SET $setClause WHERE table_id = (SELECT table_id FROM General_TABLE_Tables WHERE table_name = ?) AND $whereClause",
                'bindings' => [$tableName]
            ];
        }
        throw new Exception('Invalid UPDATE query.');
    }


    private function generateDropDatabaseQuery($query)
    {
        // Check if the query matches a DROP DATABASE statement
        if (preg_match('/DROP\s+DATABASE\s+([a-zA-Z0-9_]+)/i', $query, $matches)) {
            $dbName = $matches[1];

            // Use Laravel's DB facade to safely escape the database name
            $escapedDbName = $dbName; // No additional escaping needed for prepared statements

            // Begin a database transaction
            DB::beginTransaction();

            try {
                $query1 = "DELETE FROM general_fkey_attribute_tables WHERE constraint_id IN (SELECT id_bd FROM general_bd_tables WHERE db_name = ?)";
                DB::statement($query1, [$escapedDbName]);
                $executedQueries[] = $query1;

                $query2 = "DELETE FROM general_attribute_tables WHERE id_table IN (SELECT id_bd FROM general_bd_tables WHERE db_name = ?)";
                DB::statement($query2, [$escapedDbName]);
                $executedQueries[] = $query2;

                $query3 = "DELETE FROM general_fkey_tables WHERE source_table_id IN (SELECT id_bd FROM general_bd_tables WHERE db_name = ?) OR target_table_id IN (SELECT id_bd FROM general_bd_tables WHERE db_name = ?)";
                DB::statement($query3, [$escapedDbName, $escapedDbName]);
                $executedQueries[] = $query3;

                $query4 = "DELETE FROM general_bd_tables WHERE db_name = ?";
                DB::statement($query4, [$escapedDbName]);
                $executedQueries[] = $query4;

                // Now, drop the database
                $query5 = "DROP DATABASE IF EXISTS `$escapedDbName`";
                DB::statement($query5);
                $executedQueries[] = $query5;
                // Commit the transaction
                return response()->json([
                    'message' => 'Database and associated records dropped successfully.',
                    'queries' => $executedQueries,
                ]);
                DB::commit();

                // Return success response

            } catch (Exception $e) {
                // Rollback the transaction if any query fails
                DB::rollBack();

                // Log the exception details
                Log::error('Failed to execute DROP DATABASE query', [
                    'exception_message' => $e->getMessage(),
                    'exception_code' => $e->getCode(),
                    'stack_trace' => $e->getTraceAsString(),
                ]);

                // Return a generic error response
            }
        }

        // If the query doesn't match the DROP DATABASE pattern, throw an exception
        throw new Exception('Invalid DROP DATABASE query.');
    }





    // Gérer les requêtes ALTER TABLE (par exemple, ajouter une colonne)
    private function generateAlterTableQuery($query)
    {
        if (preg_match('/ALTER\s+TABLE\s+([a-zA-Z0-9_]+)\s+ADD\s+([a-zA-Z0-9_]+)\s+([a-zA-Z0-9_]+)/i', $query, $matches)) {
            $tableName = $matches[1];
            $attributeName = $matches[2];
            $dataType = $matches[3];
            return [
                'sql' => 'INSERT INTO General_ATTRIBUTE_Tables (table_id, attribute_name, data_type, is_primary_key, is_foreign_key, timestamp_insert) VALUES ((SELECT table_id FROM General_TABLE_Tables WHERE table_name = ?), ?, ?, FALSE, FALSE, NOW())',
                'bindings' => [$tableName, $attributeName, $dataType]
            ];
        }
        throw new Exception('Invalid ALTER TABLE query.');
    }
}
