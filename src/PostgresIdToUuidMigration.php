<?php

/*
 * This file is part of the cap-collectif/id-to-uuid project.
 *
 * (c) Cap Collectif <coucou@cap-collectif.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CapCollectif\IdToUuid;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\GuidType;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Ramsey\Uuid\Uuid;

/**
 * @author Dmitrii Poddubnyi <dpoddubny@gmail.com>
 */
class PostgresIdToUuidMigration extends AbstractMigration
{
    /** @var array */
    private $idToUuidMap;
    /** @var array */
    private $pk;
    /** @var array */
    private $fks;
    /** @var string */
    private $table;

    public function up(Schema $schema): void
    {
    }

    public function migrate(string $tableName, string $uuidColumnName = '__uuid__'): void
    {
        $this->write('Migrating ' . $tableName . '.id to UUIDs...');
        $this->prepare($tableName);
        $this->addUuidFields($uuidColumnName);
        $this->generateUuidsToReplaceIds($uuidColumnName);
        $this->addThoseUuidsToTablesWithFK();
        $this->deletePreviousFKs();
        $this->renameNewFKsToPreviousNames();
        $this->dropIdPrimaryKeyAndSetUuidToPrimaryKey($uuidColumnName);
        $this->restoreConstraintsAndIndexes();
        $this->write('Successfully migrated ' . $tableName . '.id to UUIDs!');
    }

    public function down(Schema $schema):void
    {
    }

    private function isForeignKeyNullable(Table $table, $key): bool
    {
        foreach ($table->getColumns() as $column) {
            if ($column->getName() === $key) {
                return !$column->getNotnull();
            }
        }
        throw new \Exception('Unable to find ' . $key . 'in ' . $table->getName());
    }

    private function prepare(string $tableName): void
    {
        $this->table = $tableName;
        $this->fks = [];
        $this->idToUuidMap = [];
        foreach ($this->sm->listTables() as $table) {
            if ($this->table === $table->getName()) {
                $this->validateNotUuid($table->getColumn('id'));
                $pk = $table->getPrimaryKey();
                $sequence = $this->connection->fetchColumn("SELECT pg_get_serial_sequence('{$this->table}', 'id')");
                $this->pk = [
                    'pkName' => $pk ? $pk->getName() : null,
                    'pkColumns' => $pk ? $pk->getColumns() : null,
                    'sequence' => $sequence ?? $this->table . '_id_seq',
                ];
            }
            $foreignKeys = $this->sm->listTableForeignKeys($table->getName());
            foreach ($foreignKeys as $foreignKey) {
                $key = $foreignKey->getColumns()[0];
                if ($foreignKey->getForeignTableName() === $this->table) {
                    $pk = $table->getPrimaryKey();
                    $fk = [
                        'table' => $table->getName(),
                        'key' => $key,
                        'tmpKey' => $key . '_to_uuid',
                        'nullable' => $this->isForeignKeyNullable($table, $key),
                        'name' => $foreignKey->getName(),
                        'pkName' => $pk ? $pk->getName() : null,
                        'pkColumns' => $pk ? $pk->getColumns() : null,
                    ];
                    if ($foreignKey->onDelete()) {
                        $fk['onDelete'] = $foreignKey->onDelete();
                    }
                    $this->fks[] = $fk;
                }
            }
        }
        if (count($this->fks) > 0) {
            $this->write('-> Detected the following foreign keys :');
            foreach ($this->fks as $fk) {
                $this->write('  * ' . $fk['table'] . '.' . $fk['key']);
            }
            return;
        }
        $this->write('-> 0 foreign key detected.');
    }

    private function addUuidFields(string $uuidColumnName): void
    {
        $this->connection->executeQuery("ALTER TABLE {$this->table} ADD $uuidColumnName UUID DEFAULT NULL");
        $this->connection->executeQuery("COMMENT ON COLUMN {$this->table}.$uuidColumnName IS '(DC2Type:uuid)'");

        foreach ($this->fks as $fk) {
            $fkTable = $fk['table'];
            $fkTmpKey = $fk['tmpKey'];
            $this->connection->executeQuery("ALTER TABLE {$fkTable} ADD {$fkTmpKey} UUID DEFAULT NULL");
            $this->connection->executeQuery("COMMENT ON COLUMN {$fkTable}.{$fkTmpKey} IS '(DC2Type:uuid)'");
        }
    }

    private function generateUuidsToReplaceIds(string $uuidColumnName): void
    {
        if (!class_exists('Ramsey\Uuid\Uuid')) {
            throw new \Exception('Ramsey\Uuid is required');
        }
        $fetchs = $this->connection->fetchAll("SELECT id from {$this->table} order by id ASC");
        if (count($fetchs) > 0) {
            $this->write('-> Generating ' . count($fetchs) . ' UUID(s)...');
            foreach ($fetchs as $fetch) {
                $id = $fetch['id'];
                $uuid = Uuid::uuid4()->toString();
                $this->idToUuidMap[$id] = $uuid;
                $this->connection->update($this->table, [$uuidColumnName => $uuid], ['id' => $id]);
            }
        }
    }

    private function addThoseUuidsToTablesWithFK(): void
    {
        if (0 === count($this->fks)) {
            return;
        }
        $this->write('-> Adding UUIDs to tables with foreign keys...');
        foreach ($this->fks as $fk) {
            $selectPk = implode(',', $fk['pkColumns']);
            $fetchs = $this->connection->fetchAll('SELECT ' . $selectPk . ', ' . $fk['key'] . ' FROM ' . $fk['table']);
            if (count($fetchs) > 0) {
                $this->write('  * Adding ' . count($fetchs) . ' UUIDs to "' . $fk['table'] . '.' . $fk['key'] . '"...');
                foreach ($fetchs as $fetch) {
                    // do something when the value of foreign key is not null
                    if ($fetch[$fk['key']]) {
                        $queryPk = array_flip($fk['pkColumns']);
                        foreach ($queryPk as $key => $value) {
                            $queryPk[$key] = $fetch[$key];
                        }
                        $this->connection->update(
                            $fk['table'],
                            [$fk['tmpKey'] => $this->idToUuidMap[$fetch[$fk['key']]]],
                            $queryPk
                        );
                    }
                }
            }
        }
    }

    private function deletePreviousFKs(): void
    {
        $this->write('-> Deleting previous id foreign keys...');
        foreach ($this->fks as $fk) {
//            if (isset($fk['pkName'])) {
//                try {
//                    // drop primary key if not already dropped
//                    $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP CONSTRAINT ' . $fk['pkName']);
//                } catch (\Exception $e) {
//                }
//            }
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP CONSTRAINT ' . $fk['name']);
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP COLUMN ' . $fk['key']);
        }
    }

    private function renameNewFKsToPreviousNames(): void
    {
        $this->write('-> Renaming temporary uuid foreign keys to previous foreign keys names...');
        foreach ($this->fks as $fk) {
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' RENAME COLUMN ' . $fk['tmpKey'] . ' TO ' . $fk['key']);
            if (!$fk['nullable']) {
                $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ALTER COLUMN ' . $fk['key'] . ' SET NOT NULL');
            }
        }
    }

    private function dropIdPrimaryKeyAndSetUuidToPrimaryKey(string $uuidColumnName): void
    {
        $this->write('-> Creating the uuid primary key...');

        $pkName = $this->pk['pkName'];
        $pkIds = implode(',', $this->pk['pkColumns']);

        $this->connection->executeQuery("ALTER TABLE {$this->table} DROP CONSTRAINT {$pkName}");

        $this->connection->executeQuery("ALTER TABLE {$this->table} DROP COLUMN id");
        $this->connection->executeQuery('DROP SEQUENCE IF EXISTS ' . $this->pk['sequence']);

        $this->connection->executeQuery("ALTER TABLE {$this->table} RENAME COLUMN $uuidColumnName TO id");
        $this->connection->executeQuery("ALTER TABLE {$this->table} ALTER COLUMN id SET NOT NULL");
        $this->connection->executeQuery("ALTER TABLE {$this->table} ADD CONSTRAINT {$pkName} PRIMARY KEY ({$pkIds})");
    }

    private function restoreConstraintsAndIndexes(): void
    {
        foreach ($this->fks as $fk) {
//            if (isset($fk['pkName'])) {
//                try {
//                    // restore primary key if not already restored
//                    $this->connection->executeQuery('ALTER TABLE ' . $fk['table']
//                        . ' ADD CONSTRAINT ' . $fk['pkName']
//                        . ' PRIMARY KEY (' . implode(',', $fk['pkColumns']) . ')');
//                } catch (\Exception $e) {
//                }
//            }

            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD CONSTRAINT ' . $fk['name']
                . ' FOREIGN KEY (' . $fk['key'] . ') REFERENCES ' . $this->table . ' (id)'
                . (isset($fk['onDelete']) ? ' ON DELETE ' . $fk['onDelete'] : ''));

            $idxName = str_replace(['FK_', 'fk_'], ['IDX_', 'idx_'], $fk['name']);
            $this->connection->executeQuery('CREATE INDEX ' . $idxName . ' ON ' . $fk['table'] . ' (' . $fk['key'] . ')');
        }
    }

    private function validateNotUuid(Column $column): void
    {
        if (in_array(get_class($column->getType()), [GuidType::class, 'Ramsey\\Uuid\\Doctrine\\UuidType'], true)) {
            throw new \Exception("Field {$this->table}.{$column->getName()} is already UUID");
        }
    }
}
