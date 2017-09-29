# id-to-uuid

Easily migrate from an auto incremented integer id to a [uuid](https://en.wikipedia.org/wiki/Universally_unique_identifier) in a project using [DoctrineMigrationsBundle](https://github.com/doctrine/DoctrineMigrationsBundle).
Autodetect your foreign keys and update them. **Works only on MySQL**.

## Installation

```
composer require cap-collectif/id-to-uuid
```

## Usage

1. Update your `id` column from `integer` to `guid`:

```diff
# User.orm.xml
<entity name="AppBundle\Entity\User" table="user">
---    <id name="id" column="id" type="integer">
---        <generator strategy="AUTO" />
+++    <id name="id" column="id" type="guid">
+++        <generator strategy="UUID" />
    </id>
 #...
</entity>
```

Alternatively you can use [uuid-dotrine](https://github.com/ramsey/uuid-doctrine) to add `uuid` type support.

2. Add a new migration:

```php
// app/DoctrineMigrations/VersionXYZ.php
<?php

namespace Application\Migrations;

use Doctrine\DBAL\Schema\Schema;
use CapCollectif\IdToUuid\IdToUuidMigration;

class VersionXYZ extends IdToUuidMigration
{
    public function postUp(Schema $schema)
    {
        $this->migrate('user');
    }
}
```
