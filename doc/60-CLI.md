Director CLI
============

Large parts of the Director's functionality are also available on your CLI.


Manage Objects
--------------

Use `icingacli director <type> <action>` show, create modify or delete
Icinga objects of a specific type:

| Action       | Description                           |
|--------------|---------------------------------------|
| `create`     | Create a new object                   |
| `delete`     | Delete a specific object              |
| `exists`     | Whether a specific object exists      |
| `set`        | Modify an existing objects properties |
| `show`       | Show a specific object                |


Currently the following object types are available on CLI:

* endpoint
* host
* hostgroup
* notification
* service
* timeperiod
* user
* usergroup
* zone


### Create a new object

Use this command to create a new Icinga object


#### Usage

`icingacli director <type> create [<name>] [options]`


#### Options

| Option            | Description                                           |
|-------------------|-------------------------------------------------------|
| `--<key> <value>` | Provide all properties as single command line options |
| `--json`          | Otherwise provide all options as a JSON string        |


#### Examples

To create a new host you can provide all it's properties as command line
parameters:

```shell
icingacli director host create localhost \
    --imports generic-host \
    --address 127.0.0.1 \
    --vars.location 'My datacenter'
```

It would say:

    Host 'localhost' has been created

Providing structured data could become tricky that way. Therefore you are also
allowed to provide JSON formatted properties:

```shell
icingacli director host create localhost \
    --json '{ "address": "127.0.0.1", "vars": { "test": [ "one", "two" ] } }'
```


### Delete a specific object

Use this command to delete a single Icinga object. Just run

    icingacli director <type> delete <name>

That's it. To delete the host created before, this would read

    icingacli director host delete localhost

It will tell you whether your command succeeded:

    Host 'localhost' has been deleted


### Whether a specific object exists

Use this command to find out whether a single Icinga object exists. Just
run:

    icingacli director <type> exists <name>

So if you run...

    icingacli director host exists localhost

...it will either tell you ...

    Host 'localhost' exists

...or:
 
    Host 'localhost' does not exist

When executed from custom scripts you could also just check the exit code,
`0` means that the object exists, `1` that it doesn't.


### Modify an existing objects properties

Use this command to modify specific properties of an existing Icinga object.


#### Usage

    icingacli director <type> set <name> [options]


#### Options

| Option            | Description                                           |
|-------------------|-------------------------------------------------------|
| `--<key> <value>` | Provide all properties as single command line options |
| `--json`          | Otherwise provide all options as a JSON string        |
| `--replace`       | Replace all object properties with the given ones     |
| `--auto-create`   | Create the object in case it does not exist           |


#### Examples

```shell
icingacli director host set localhost \
    --address 127.0.0.2 \
    --vars.location 'Somewhere else'
```

It will either tell you

    Host 'localhost' has been modified

or, when for example issued immediately a second time:

    Host 'localhost' has not been modified

Like create, this also allows you to provide JSON-formatted properties:

```shell
icingacli director host set localhost --json '{ "address": "127.0.0.2" }'
```

This command will fail in case the specified object does not exist. This is
when the `--auto-create` parameter comes in handy. Command output will thell
you whether an object has either be created or (not) modified.

With `set` you only set the specified properties and do not touch the other
ones. You could also want to completely override an object, purging all other
eventually existing and unspecified parameters. Please use `--replace` if this
is the desired behaviour.


### Show a specific object

Use this command to show single objects rendered as Icinga 2 config or
in JSON format.


#### Usage

`icingacli director <type> show <name> [options]`


#### Options

| Option          | Description                                             |
|-----------------|---------------------------------------------------------|
| `--resolved`    | Resolve all inherited properties and show a flat object |
|                 | object                                                  |
| `--json`        | Use JSON format                                         |
| `--no-pretty`   | JSON is pretty-printed per default (for PHP >= 5.4)     |
|                 | Use this flag to enforce unformatted JSON               |
| `--no-defaults` | Per default JSON output skips null or default values    |
|                 | With this flag you will get all properties              |


### Other interesting tasks


#### Rename objects

There is no rename command, but a simple `set` can easily accomplish this task:

    icingacli director host set localhost --object_name localhost2

Please note that it is usually absolutely no problem to rename objects with
the Director. Even renaming something essential as a template like the famous
`generic-host` will not cause any trouble. At least not unless you have other
components outside your Director depending on that template.


#### Disable an object

Objects can be disabled. That way they will still exist in your Director DB,
but they will not be part of your next deployment. Toggling the `disabled`
property is all you need:

    icingacli director host set localhost --disabled

Valid values for booleans are `y`, `n`, `1` and `0`. So to re-enable an object
you could use:

    icingacli director host set localhost --disabled n


#### Working with booleans

As we learned before, `y`, `n`, `1` and `0` are valid values for booleans. But
custom variables have no data type. And even if there is such, you could always
want to change or override this from CLI. So you usually need to provide booleans
in JSON format in case you need them in a custom variable.

There is however one exception from this rule. CLI parameters without a given
value are handled as boolean flags by the Icinga Web 2 CLI. That explains why
the example disabling an object worked without passing `y` or `1`. You could
use this also to set a custom variable to boolean `true`:

    icingacli director host set localhost --vars.some_boolean

Want to change it to false? No chance this way, you need to pass JSON:

    icingacli director host set localhost --json '{ "vars.some_boolean": false }'

This example shows the dot-notation to set a specific custom variable. If we
have had used `{ "vars": { "some_boolean": false } }`, all other custom vars
on this object would have been removed.


#### Change object types

The Icinga Director distincts between the following object types:

| Type              | Description
|-------------------|-------------------------------------------------------------|
| `object`          | The default object type. A host, a command and similar      |
| `template`        | An Icinga template                                          |
| `apply`           | An apply rule. This allows for assign rules                 |
| `external_object` | An external object. Can be referenced and used, will not be |
|                   | deployed                                                    |

Please take a lot of care when modifying object types, you should not do so for
a good reason. The CLI allows you to issue operations that are not allowed in the
web frontend. Do not use this unless you really understand it's implications. And
remember, with great power comes great responsibility.


Kickstart and schema handling
-----------------------------

The `kickstart` and the `migration` command are handled in the [automation section](03-Automation.md),
so they are skipped here.


Configuration handling
----------------------

### Render your configuration

The Director distincts between rendering and deploying your configuration.
Rendering means that Icinga 2 config will be pre-rendered and stored to the
Director DB. Nothing bad happens if you decide to render the current config
thousands of times in a loop. In case a config with the same checksum already
exists, it will store - nothing.

You can trigger config rendering by running

```shell
icingacli director config render
```

In case a new config has been created, it will tell you so:
```
New config with checksum b330febd0820493fb12921ad8f5ea42102a5c871 has been generated
```

Run it once again, and you'll see that the output changes:
```
Config with checksum b330febd0820493fb12921ad8f5ea42102a5c871 already exists
```


### Config deployment

You do not need to explicitely render your config before deploying it to your
Icinga 2 master node. Just trigger a deployment, it will re-render the current
config:

```shell
icingacli director config deploy 
```

The output tells you which config has been shipped:

```
Config 'b330febd0820493fb12921ad8f5ea42102a5c871' has been deployed
```

Director tries to avoid needless deployments, so in case you immediately deploy
again, the output changes:
```
Config matches active stage, nothing to do
```

You can override this by adding the `--force` parameter. It will then tell you:

```
Config matches active stage, deploying anyway
```

In case you want to do not want `deploy` to waste time to re-render your
config or in case you decide to re-deploy a specific, eventually older config
version the `deploy` command allows you to provide a specific checksum:

```shell
icingacli director config deploy --checksum b330febd0820493fb12921ad8f5ea42102a5c871
```


### Cronjob usage

You could decide to pre-render your config in the background quite often. As of
this writing this has one nice advantage. It allows the GUI to find out whether
a bunch of changes still results into the very same config. 
only one 


Run sync and import jobs
------------------------

The `jobs` command runs pending Import and Sync jobs. Please note that we have
planned a scheduler configurable through the Icinga Director web interface, but
this is not available yes.

So the only option you have right now is to trigger all jobs at once:

```shell
icingacli director jobs run
```

The output could look as follows:

```
Import "Puppet DB (PE 2015)" provides changes, triggering run... SUCCEEDED
Sync rule "Hosts from PE2015" provides changes, triggering sync... SUCCEEDED
```

Database housekeeping
---------------------

Your database may grow over time and ask for various housekeeping tasks. You
can usually store a lot of data in your Director DB before you would even
notice a performance impact. 

Still, we started to prepare some tasks that assist with removing useless
garbage from your DB. You can show available tasks with:

    icingacli director housekeeping tasks

The output might look as follows:

```
 Housekeeping task (name)                                  | Count
-----------------------------------------------------------|-------
 Undeployed configurations (oldUndeployedConfigs)          |     3
 Unused rendered files (unusedFiles)                       |     0
 Unlinked imported row sets (unlinkedImportedRowSets)      |     0
 Unlinked imported rows (unlinkedImportedRows)             |     0
 Unlinked imported properties (unlinkedImportedProperties) |     0
```

You could run a specific task with

    icingacli director housekeeping run <taskName>

...like in:

    icingacli director housekeeping run unlinkedImportedRows

Or you could also run all of them, that's the preferred way of doing this:

    icingacli director housekeeping run ALL

Please note that some tasks once issued create work for other tasks, as
lost imported rows might appear once you remove lost row sets. So `ALL`
is usually the best choice as it runs all of them in the best order.