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

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Id\UuidGenerator;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class IdToUuidMigration extends AbstractMigration implements ContainerAwareInterface
{
    protected $em;
    protected $idToUuidMap = [];
    protected $generator;
    protected $fks;
    protected $table;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->em = $container->get('doctrine')->getManager();
        $this->connection = $this->em->getConnection();
        $this->schemaManager = $this->connection->getSchemaManager();
        $this->generator = new UuidGenerator();
    }

    public function up(Schema $schema)
    {
    }

    public function migrate(string $tableName)
    {
        echo PHP_EOL . 'Migrating ' . $tableName . '.id to UUIDs...' . PHP_EOL;
        $this->prepare($tableName);
        $this->addUuidFields();
        $this->generateUuidsToReplaceIds();
        $this->addThoseUuidsToTablesWithFK();
        $this->deletePreviousFKs();
        $this->renameNewFKsToPreviousNames();
        $this->dropIdPrimaryKeyAndSetUuidToPrimaryKey();
        $this->restoreConstraintsAndIndexes();
        echo 'Successfully migrated ' . $tableName . '.id to UUIDs!' . PHP_EOL . PHP_EOL;
    }

    public function postUp(Schema $schema)
    {
    }

    public function down(Schema $schema)
    {
    }

    private function isForeignKeyNullable($table, $key)
    {
        foreach ($table->getColumns() as $column) {
            if ($column->getName() === $key) {
                return !$column->getNotnull();
            }
        }
        throw new \Exception('Unable to find ' . $key . 'in ' . $table);
    }

    private function prepare(string $tableName)
    {
        $this->table = $tableName;
        $this->fks = [];
        $this->idToUuidMap = [];

        foreach ($this->schemaManager->listTables() as $table) {
            $foreignKeys = $this->schemaManager->listTableForeignKeys($table->getName());
            foreach ($foreignKeys as $foreignKey) {
                $key = $foreignKey->getColumns()[0];
                if ($foreignKey->getForeignTableName() === $this->table) {
                    $fk = [
                      'table' => $table->getName(),
                      'key' => $key,
                      'tmpKey' => $key . '_to_uuid',
                      'nullable' => $this->isForeignKeyNullable($table, $key),
                      'name' => $foreignKey->getName(),
                    ];
                    if ($foreignKey->onDelete()) {
                        $fk['onDelete'] = $foreignKey->onDelete();
                    }
                    if (in_array($key, $table->getPrimaryKeyColumns(), true)) {
                        // if the foreign key is present in the primary key
                        // we must also remove temporary the primary key
                        $fk['primaryKey'] = $table->getPrimaryKeyColumns();
                    }
                    $this->fks[] = $fk;
                }
            }
        }
        if (count($this->fks) > 0) {
            echo '-> Detected the following foreign keys :' . PHP_EOL;
            foreach ($this->fks as $fk) {
                echo '  * ' . $fk['table'] . '.' . $fk['key'] . PHP_EOL;
            }

            return;
        }
        echo '-> 0 foreign key detected.' . PHP_EOL;
    }

    private function addUuidFields()
    {
        $this->connection->executeQuery('ALTER TABLE ' . $this->table . ' ADD uuid CHAR(36) COMMENT \'(DC2Type:guid)\' FIRST');
        foreach ($this->fks as $fk) {
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD ' . $fk['tmpKey'] . ' CHAR(36) COMMENT \'(DC2Type:guid)\'');
        }
    }

    private function generateUuidsToReplaceIds()
    {
        $fetchs = $this->connection->fetchAll('SELECT id from ' . $this->table);
        if (count($fetchs) > 0) {
            echo '-> Generating ' . count($fetchs) . ' UUID(s)...' . PHP_EOL;
            foreach ($fetchs as $fetch) {
                $id = $fetch['id'];
                $uuid = $this->generator->generate($this->em, null);
                $this->idToUuidMap[$id] = $uuid;
                $this->connection->update($this->table, ['uuid' => $uuid], ['id' => $id]);
            }
        }
    }

    private function addThoseUuidsToTablesWithFK()
    {
        if (count($this->fks) === 0) {
            return;
        }
        echo '-> Adding UUIDs to tables with foreign keys...' . PHP_EOL;
        foreach ($this->fks as $fk) {
            $selectPk = isset($fk['primaryKey']) ? implode(',', $fk['primaryKey']) : 'id';
            $fetchs = $this->connection->fetchAll('SELECT ' . $selectPk . ', ' . $fk['key'] . ' FROM ' . $fk['table']);
            if (count($fetchs) > 0) {
                echo '  * Adding ' . count($fetchs) . ' UUIDs to "' . $fk['table'] . '.' . $fk['key'] . '"...' . PHP_EOL;
                foreach ($fetchs as $fetch) {
                    if ($fetch[$fk['key']]) {
                        // do something when the value of foreign key is not null
                    if (!isset($fk['primaryKey'])) {
                        $queryPk = ['id' => $fetch['id']];
                    } else {
                        $queryPk = array_flip($fk['primaryKey']);
                        foreach ($queryPk as $key => $value) {
                            $queryPk[$key] = $fetch[$key];
                        }
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

    private function deletePreviousFKs()
    {
        echo '-> Deleting previous id foreign keys...' . PHP_EOL;
        foreach ($this->fks as $fk) {
            if (isset($fk['primaryKey'])) {
                try {
                    // drop primary key if not already dropped
                    $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP PRIMARY KEY');
                } catch (\Exception $e) {
                }
            }
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP FOREIGN KEY ' . $fk['name']);
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP COLUMN ' . $fk['key']);
        }
    }

    private function renameNewFKsToPreviousNames()
    {
        echo '-> Renaming temporary uuid foreign keys to previous foreign keys names...' . PHP_EOL;
        foreach ($this->fks as $fk) {
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' CHANGE ' . $fk['tmpKey'] . ' ' . $fk['key'] . ' CHAR(36) ' . ($fk['nullable'] ? '' : 'NOT NULL ') . 'COMMENT \'(DC2Type:guid)\'');
        }
    }

    private function dropIdPrimaryKeyAndSetUuidToPrimaryKey()
    {
        echo '-> Creating the uuid primary key...' . PHP_EOL;
        $this->connection->executeQuery('ALTER TABLE ' . $this->table . ' DROP PRIMARY KEY, DROP COLUMN id');
        $this->connection->executeQuery('ALTER TABLE ' . $this->table . ' CHANGE uuid id CHAR(36) NOT NULL COMMENT \'(DC2Type:guid)\'');
        $this->connection->executeQuery('ALTER TABLE ' . $this->table . ' ADD PRIMARY KEY (id)');
    }

    private function restoreConstraintsAndIndexes()
    {
        foreach ($this->fks as $fk) {
            if (isset($fk['primaryKey'])) {
                try {
                    // restore primary key if not already restored
                    $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD PRIMARY KEY (' . implode(',', $fk['primaryKey']) . ')');
                } catch (\Exception $e) {
                }
            }
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD CONSTRAINT ' . $fk['name'] . ' FOREIGN KEY (' . $fk['key'] . ') REFERENCES ' . $this->table . ' (id)' .
              (isset($fk['onDelete']) ? ' ON DELETE ' . $fk['onDelete'] : '')
            );
            $this->connection->executeQuery('CREATE INDEX ' . str_replace('FK_', 'IDX_', $fk['name']) . ' ON ' . $fk['table'] . ' (' . $fk['key'] . ')');
        }
    }
}
