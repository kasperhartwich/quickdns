<?php

namespace QuickDns\Testing;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use QuickDns\QuickDns;

/**
 * An in-memory quickdns.dk for tests: a Guzzle handler that keeps zones, templates, groups and
 * records and answers with the same pages and XML as QuickDNS, so code using QuickDns can be
 * tested without the network.
 *
 *     $fake = new FakeQuickDns();
 *     $fake->addTemplate('standard');
 *     $quickDns = $fake->quickDns();
 *     (new Zone($quickDns, 'example.dk'))->create();
 *     $fake->hasZone('example.dk'); // true
 *
 * To put it behind your own middleware, use it as the stack's handler:
 * HandlerStack::create($fake). The stack must include Guzzle's cookie middleware (create() adds
 * it), since the fake checks the login session cookie.
 *
 * Like QuickDNS, every new zone gets the four NS records of the template "QuickDNS global".
 * Requests without a logged-in session get the login page.
 */
final class FakeQuickDns
{
    /**
     * The account's user id in command answers (QuickDNS sends the real one in <user>).
     */
    public const USER = 1000;

    private string $email;

    private string $password;

    private int $nextId = 1000;

    /** @var array<int, array{domain: string, templates: int[], groups: int[], records: array<int, array>, updated: string}> */
    private array $zones = [];

    /** @var array<int, array{name: string, groups: int[], updated: string}> */
    private array $templates = [];

    /** @var array<int, array{name: string}> */
    private array $groups = [];

    /** @var string[] Session ids that have logged in */
    private array $sessions = [];

    /** @var RequestInterface[] */
    private array $requests = [];

    /**
     * Open zone edit sessions, keyed by session key: the pending table, the rows marked bad, and
     * the errors reported so far.
     *
     * @var array<string, array{zone: int, rows: array<int, ?array>, bad: int[], errors: string[], seq: int}>
     */
    private array $editSessions = [];

    /** @var string[] Errors to answer the next changes with, set by failNextChange() */
    private array $forcedErrors = [];

    public function __construct(string $email = 'test@example.dk', string $password = 'secret')
    {
        $this->email = $email;
        $this->password = $password;
    }

    /**
     * A QuickDns logged in to this fake, with the same credentials.
     */
    public function quickDns(): QuickDns
    {
        return new QuickDns($this->email, $this->password, $this->client());
    }

    /**
     * A Guzzle client that sends every request to this fake.
     */
    public function client(): ClientInterface
    {
        return new Client(['handler' => HandlerStack::create($this)]);
    }

    /**
     * Add a zone as if created on quickdns.dk.
     *
     * @param  string[]  $templates  Names of existing templates
     * @param  string[]  $groups  Names of existing groups
     * @return int The zone's id
     */
    public function addZone(string $domain, array $templates = [], array $groups = []): int
    {
        $id = $this->nextId++;
        $this->zones[$id] = [
            'domain' => $domain,
            'templates' => array_map(fn ($name) => $this->templateId($name), $templates),
            'groups' => array_map(fn ($name) => $this->groupId($name), $groups),
            'records' => [],
            'updated' => $this->now(),
        ];
        foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $ns) {
            $this->zones[$id]['records'][] = ['name' => '@', 'ttl' => null, 'type' => 'NS', 'priority' => null, 'value' => $ns.'.quickdns.dk.', 'template' => 'QuickDNS global'];
        }

        return $id;
    }

    /**
     * Add a record to a zone, e.g. addRecord('example.dk', '@', 'MX', 'mx1.example.dk.', 3600, 10).
     */
    public function addRecord(string $domain, string $name, string $type, string $value, ?int $ttl = 3600, ?int $priority = null): void
    {
        $this->zones[$this->zoneId($domain)]['records'][] = ['name' => $name, 'ttl' => $ttl, 'type' => $type, 'priority' => $priority, 'value' => $value, 'template' => null];
    }

    /**
     * @return int The template's id
     */
    public function addTemplate(string $name): int
    {
        $id = $this->nextId++;
        $this->templates[$id] = ['name' => $name, 'groups' => [], 'updated' => $this->now()];

        return $id;
    }

    /**
     * @return int The group's id
     */
    public function addGroup(string $name): int
    {
        $id = $this->nextId++;
        $this->groups[$id] = ['name' => $name];

        return $id;
    }

    public function hasZone(string $domain): bool
    {
        return $this->findZone($domain) !== null;
    }

    public function hasTemplate(string $name): bool
    {
        return $this->find($this->templates, $name) !== null;
    }

    public function hasGroup(string $name): bool
    {
        return $this->find($this->groups, $name) !== null;
    }

    /**
     * @return string[] Names of the templates a zone uses
     */
    public function templatesOf(string $domain): array
    {
        return array_map(fn ($id) => $this->templates[$id]['name'], $this->zones[$this->zoneId($domain)]['templates']);
    }

    /**
     * @return string[] Names of the groups a zone is in
     */
    public function groupsOf(string $domain): array
    {
        return array_map(fn ($id) => $this->groups[$id]['name'], $this->zones[$this->zoneId($domain)]['groups']);
    }

    /**
     * Every request this fake has answered, oldest first.
     *
     * @return RequestInterface[]
     */
    public function requests(): array
    {
        return $this->requests;
    }


    /**
     * Make the next change answer with this error, as QuickDNS would, so a test can exercise the
     * rejected path without guessing what the service dislikes.
     */
    public function failNextChange(string $error = 'Noget gik galt.'): void
    {
        $this->forcedErrors[] = $error;
    }

    /**
     * The zone's saved records, as arrays: name, ttl, type, priority, value, template.
     *
     * @return array<int, array>
     */
    public function recordsOf(string $domain): array
    {
        return $this->zones[$this->zoneId($domain)]['records'];
    }

    /**
     * True while an edit session on the zone has changes that were never saved or discarded.
     */
    public function hasPendingChanges(string $domain): bool
    {
        $id = $this->zoneId($domain);
        foreach ($this->editSessions as $session) {
            if ($session['zone'] === $id && $session['rows'] !== $this->pendingRows($id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The pending table of a zone: row 0 is the header, then one row per record.
     *
     * @return array<int, ?array>
     */
    private function pendingRows(int $id): array
    {
        return array_merge([null], array_values($this->zones[$id]['records']));
    }

    /**
     * submitzonechange: one change against the pending table.
     */
    private function zoneChange(array $query): PromiseInterface
    {
        $key = (string) ($query['zkey'] ?? '');
        if (! isset($this->editSessions[$key])) {
            return $this->changeXml([], ['Ukendt session.'], [0]);
        }
        $session = &$this->editSessions[$key];
        $session['seq'] = (int) ($query['seq'] ?? 0);
        $action = (string) ($query['action'] ?? '');
        $actions = [];

        if ($action === 'edit') {
            $record = [
                'name' => (string) ($query['record'] ?? ''),
                'ttl' => ($query['ttl'] ?? '') === '' ? null : (int) $query['ttl'],
                'type' => (string) ($query['type'] ?? ''),
                'priority' => ($query['priority'] ?? '') === '' ? null : (int) $query['priority'],
                'value' => (string) ($query['value'] ?? ''),
                'template' => null,
            ];
            // QuickDNS keeps a priority for MX and SRV only.
            if (! in_array($record['type'], ['MX', 'SRV'], true)) {
                $record['priority'] = null;
            }
            $row = (int) ($query['row'] ?? -1);
            if ($row === -1) {
                $row = count($session['rows']);
                $session['rows'][$row] = null;
                $actions[] = ['action' => 'insertrow', 'row' => $row];
            } elseif (! isset($session['rows'][$row])) {
                return $this->changeXml($session['rows'], ['Ukendt r&aelig;kke.'], [$row]);
            } elseif ($session['rows'][$row]['template'] !== null) {
                // Same as the live service: a template row cannot be touched on the zone.
                return new FulfilledPromise(new Response(500, ['Content-Type' => 'text/html'], 'Internal Server Error'));
            }
            $session['rows'][$row] = $record;
            $actions[] = ['action' => 'changerow', 'row' => $row, 'record' => $record];

            if (($error = $this->rejects($record)) !== null) {
                $session['bad'][] = $row;
                $session['errors'][] = $error;
            }
        } elseif ($action === 'delete') {
            $row = (int) ($query['row'] ?? 0);
            if (! isset($session['rows'][$row])) {
                return $this->changeXml($session['rows'], ['Ukendt r&aelig;kke.'], [$row]);
            }
            if ($session['rows'][$row]['template'] !== null) {
                return new FulfilledPromise(new Response(500, ['Content-Type' => 'text/html'], 'Internal Server Error'));
            }
            array_splice($session['rows'], $row, 1);
            $session['bad'] = [];
            $session['errors'] = [];
            $actions[] = ['action' => 'deleterow', 'row' => $row];
        }

        return $this->changeXml($actions, $session['errors'], $session['bad']);
    }

    /**
     * editzonedone: save the pending table, or throw it away. A session holding a rejected record
     * saves nothing at all, exactly like the live service.
     */
    private function zoneEditDone(array $query): PromiseInterface
    {
        $key = (string) ($query['zkey'] ?? '');
        if (isset($this->editSessions[$key])) {
            $session = $this->editSessions[$key];
            // Like the live service: the wrong sequence number saves nothing, without a word.
            $sequenceMatches = (int) ($query['seq'] ?? -1) === $session['seq'];
            if ((string) ($query['save'] ?? '0') === '1' && $session['bad'] === [] && $sequenceMatches) {
                $records = array_values(array_filter($session['rows']));
                // QuickDNS sorts the zone when it saves, template records first.
                usort($records, fn ($a, $b) => [$a['template'] === null, $a['name'], $a['type']] <=> [$b['template'] === null, $b['name'], $b['type']]);
                $this->zones[$session['zone']]['records'] = $records;
                $this->zones[$session['zone']]['updated'] = $this->now();
            }
            unset($this->editSessions[$key]);
        }

        return $this->zonesPage();
    }

    /**
     * Why QuickDNS would reject a record, or null when it would not.
     */
    private function rejects(array $record): ?string
    {
        if ($this->forcedErrors !== []) {
            return $this->e((string) array_shift($this->forcedErrors));
        }
        foreach (['name', 'value'] as $field) {
            if (trim((string) $record[$field]) === '' || preg_match('/["\\\']|[^\x20-\x7e]/', (string) $record[$field])) {
                return "'".$this->e((string) $record[$field])."' indeholder ugyldige tegn.&lt;br&gt;";
            }
        }
        if (! in_array($record['type'], ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'PTR', 'SPF', 'SRV', 'TXT'], true)) {
            return "'".$this->e((string) $record['type'])."' er ikke en gyldig type.&lt;br&gt;";
        }

        return null;
    }

    /**
     * The XML submitzonechange answers: a Danish status line, the errors so far, the bad rows and
     * what changed in the table.
     *
     * @param  array<int, array>  $actions
     * @param  string[]  $errors
     * @param  int[]  $bad
     */
    private function changeXml(array $actions, array $errors, array $bad): PromiseInterface
    {
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>'."\n<response>\n";
        foreach (array_unique($bad) as $row) {
            $xml .= '<badrecord>'.$row."</badrecord>\n";
        }
        $xml .= '<color>'.($errors === [] ? '#66bc29' : '#d3222a')."</color>\n";
        foreach ($errors as $error) {
            $xml .= '<error>'.$error."</error>\n";
        }
        $xml .= '<status>Status: '.($errors === [] ? 'Ingen fejl i zonen' : count($errors).' fejl i zonen')."</status>\n";
        foreach ($actions as $action) {
            $xml .= "<actions>\n<action>".$action['action']."</action>\n<row>".$action['row']."</row>\n";
            if (isset($action['record'])) {
                $record = $action['record'];
                $xml .= '<record>'.$this->e($record['name'])."</record>\n"
                    .'<ttl>'.($record['ttl'] ?? '&amp;nbsp;')."</ttl>\n"
                    .'<type>'.$this->e($record['type'])."</type>\n"
                    .'<priority>'.($record['priority'] ?? '&amp;nbsp;')."</priority>\n"
                    .'<value>'.$this->e($record['value'])."</value>\n"
                    ."<locked>0</locked>\n<changed>1</changed>\n";
            }
            $xml .= "</actions>\n";
        }
        $xml .= "</response>\n";

        return new FulfilledPromise(new Response(200, ['Content-Type' => 'text/xml'], mb_convert_encoding($xml, 'ISO-8859-1', 'UTF-8')));
    }

    /**
     * Guzzle handler.
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->requests[] = $request;
        $function = ltrim($request->getUri()->getPath(), '/');
        $query = $this->query($request->getUri()->getQuery());

        if ($function === 'login') {
            parse_str((string) $request->getBody(), $form);

            return $this->login($form['email'] ?? '', $form['password'] ?? '');
        }
        if (! in_array($this->sessionOf($request), $this->sessions, true)) {
            return $this->page('Log ind', '<form action="/login" method="post"><input type="text" name="email" /></form>', false);
        }

        return match ($function) {
            'zones' => $this->zonesPage(),
            'templates' => $this->templatesPage(),
            'groups' => $this->groupsPage(),
            'editzone' => $this->editZonePage((int) ($query['id'] ?? 0)),
            'submitzonechange' => $this->zoneChange($query),
            'editzonedone' => $this->zoneEditDone($query),
            'addzone' => $this->addZoneCommand((string) ($query['zone'] ?? '')),
            'delzone' => $this->deleteCommand($this->zones, (int) ($query['id'] ?? 0), 'Zonen er slettet', 'Zonen findes ikke'),
            'addtemplate' => $this->addTemplateCommand((string) ($query['zone'] ?? '')),
            'deltemplate' => $this->deleteTemplateCommand((int) ($query['id'] ?? 0)),
            'addgroup' => $this->addGroupCommand((string) ($query['group'] ?? '')),
            'delgroup' => $this->deleteGroupCommand((int) ($query['id'] ?? 0)),
            'updatetemplates' => $this->updateCommand('templates', $query, 'template', $this->templates),
            'updategroups' => $this->updateCommand('groups', $query, 'group', $this->groups),
            default => new FulfilledPromise(new Response(404, [], 'Not Found')),
        };
    }

    private function login(string $email, string $password): PromiseInterface
    {
        if ($email !== $this->email || $password !== $this->password) {
            return $this->page('Log ind', '<p>Beklager, email-adressen eller passwordet der er indtastet er forkert.</p>', false);
        }
        $session = bin2hex(random_bytes(8));
        $this->sessions[] = $session;

        return $this->page('Mine zoner', $this->zonesTable(), true, ['Set-Cookie' => 'PHPSESSID='.$session.'; path=/']);
    }

    private function zonesPage(): PromiseInterface
    {
        return $this->page('Mine zoner', $this->zonesTable());
    }

    private function zonesTable(): string
    {
        $rows = '';
        foreach ($this->zones as $id => $zone) {
            $rows .= '<tr class="listrow2" zoneid="'.$id.'">'
                .'<td class="listrow2">&nbsp;</td>'
                .'<td class="listrow2"><a href="/editzone?id='.$id.'">'.$this->e($zone['domain']).'</a></td>'
                .'<td class="listrow2"><a href="javascript:void(0);" onclick="zoneid = '.$id.'; templates(parentNode.parentNode.rowIndex, '.$this->idArray($zone['templates']).');">'.$this->names($zone['templates'], $this->templates).'</a></td>'
                .'<td class="listrow2"><a href="javascript:void(0);" onclick="zoneid = '.$id.'; groups(parentNode.parentNode.rowIndex, new Array(), '.$this->idArray($zone['groups']).');">'.$this->names($zone['groups'], $this->groups).'</a></td>'
                .'<td class="listrow2">'.$zone['updated'].'</td>'
                .'<td class="listrow2"><a href="javascript:void(0);">Slet</a></td>'
                ."</tr>\n";
        }

        return '<table class="listtable" id="zone_table"><tr class="listheader">'
            .'<th class="listheader">&nbsp;</th><th class="listheader">Zone</th><th class="listheader">Skabeloner</th>'
            .'<th class="listheader">Grupper</th><th class="listheader">Opdateret</th><th class="listheader">Slet</th>'
            ."</tr>\n".$rows.'</table>';
    }

    private function templatesPage(): PromiseInterface
    {
        $rows = '';
        foreach ($this->templates as $id => $template) {
            $zones = count(array_filter($this->zones, fn ($zone) => in_array($id, $zone['templates'], true)));
            $rows .= '<tr class="listrow2">'
                .'<td class="listrow2"><a href="/edittemplate?id='.$id.'">'.$this->e($template['name']).'</a></td>'
                .'<td class="listrow2">'.$zones.'</td>'
                .'<td class="listrow2"><a href="javascript:void(0);">'.$this->names($template['groups'], $this->groups).'</a></td>'
                .'<td class="listrow2">'.$template['updated'].'</td>'
                .'<td class="listrow2"><a href="javascript:void(0);">Ret</a></td>'
                .'<td class="listrow2"><a href="javascript:void(0);">Slet</a></td>'
                ."</tr>\n";
        }

        return $this->page('Mine skabeloner', '<table class="listtable" id="zone_table"><tr class="listheader">'
            .'<th class="listheader">Skabelon</th><th class="listheader">Zoner</th><th class="listheader">Grupper</th>'
            .'<th class="listheader">Opdateret</th><th class="listheader">Ret</th><th class="listheader">Slet</th>'
            ."</tr>\n".$rows.'</table>');
    }

    private function groupsPage(): PromiseInterface
    {
        $rows = '';
        foreach ($this->groups as $id => $group) {
            // Like QuickDNS, "Medlemmer" lists users, not zones; the fake has none.
            $rows .= '<tr class="listrow2">'
                .'<td class="listrow2">'.$this->e($group['name']).'</td>'
                .'<td class="listrow2"><a href="javascript:void(0);" onclick="groupid = '.$id.'; edit_members(parentNode.parentNode.rowIndex);">Ingen</a></td>'
                .'<td class="listrow2"><a href="javascript:void(0);" onclick="groupid = '.$id.'; rename(parentNode.parentNode.rowIndex);">Ret</a></td>'
                .'<td class="listrow2"><a href="javascript:void(0);" onclick="groupid = '.$id.'; del(parentNode.parentNode.rowIndex);">Slet</a></td>'
                ."</tr>\n";
        }

        return $this->page('Mine grupper', '<table class="listtable" id="group_table"><tr>'
            .'<th class="listheader">Gruppe</th><th class="listheader">Medlemmer</th><th class="listheader">Ret</th><th class="listheader">Slet</th>'
            ."</tr>\n".$rows.'</table>');
    }

    private function editZonePage(int $id): PromiseInterface
    {
        if (! isset($this->zones[$id])) {
            return $this->page('Fejl', '<p>Zonen findes ikke</p>');
        }
        $rows = '';
        foreach ($this->zones[$id]['records'] as $record) {
            $locked = $record['template'] !== null
                ? '<td title="Denne record er tilf&oslash;jet automatisk af skabelonen &quot;'.$this->e($record['template']).'&quot;">-</td>'
                : null;
            $rows .= '<tr>'
                .'<td>'.$this->e($record['name']).' </td>'
                .'<td>'.$record['ttl'].' </td>'
                .'<td>'.$record['type'].' </td>'
                .'<td>'.$record['priority'].' </td>'
                .'<td title="'.$this->e($record['value']).'">'.$this->e($record['value']).' </td>'
                .($locked ?? '<td><a href="javascript:void(0);" onclick="edit(parentNode.parentNode.rowIndex);">Ret</a></td>')
                .($locked ?? '<td><a href="javascript:void(0);" onclick="del(parentNode.parentNode.rowIndex);">Slet</a></td>')
                ."</tr>\n";
        }

        $key = bin2hex(random_bytes(32));
        $this->editSessions[$key] = ['zone' => $id, 'rows' => $this->pendingRows($id), 'bad' => [], 'errors' => [], 'seq' => 0];

        return $this->page('Mine zoner', '<p>Zone: '.$this->e($this->zones[$id]['domain']).'</p>'
            .'<script type="text/javascript">window.onload = function () { init(\''.$key.'\', false); };</script>'
            .'<table class="listtable records" id="zone_table"><tr class="listheader">'
            .'<th class="listheader">Record</th><th class="listheader">TTL</th><th class="listheader">Type</th>'
            .'<th class="listheader">Prioritet</th><th class="listheader">Værdi</th><th class="listheader">Ret</th><th class="listheader">Slet</th>'
            ."</tr>\n".$rows.'</table>');
    }

    private function addZoneCommand(string $domain): PromiseInterface
    {
        if (! preg_match('/^(?=.{1,253}$)([a-z0-9æøå](-*[a-z0-9æøå])*\.)+(dk|com|net|org|eu|se|no|de|io|nu|info)$/iu', $domain)) {
            return $this->xml('ERROR', 'Zonens navn er ugyldigt', ['user' => self::USER, 'zone' => $domain]);
        }
        if ($this->hasZone($domain)) {
            return $this->xml('ERROR', 'Zonen eksisterer allerede', ['user' => self::USER, 'zone' => $domain]);
        }

        return $this->xml('OK', 'Zonen er oprettet', ['user' => self::USER, 'zone' => $domain, 'zoneid' => $this->addZone($domain)]);
    }

    private function addTemplateCommand(string $name): PromiseInterface
    {
        if (! $this->validName($name)) {
            return $this->xml('ERROR', 'Skabelonens navn er ugyldigt', ['user' => self::USER, 'zone' => $name]);
        }
        if ($this->hasTemplate($name)) {
            return $this->xml('ERROR', 'Skabelonen eksisterer allerede', ['user' => self::USER, 'zone' => $name]);
        }

        // QuickDNS answers with the template's id in <zoneid>.
        return $this->xml('OK', 'Skabelonen er oprettet', ['user' => self::USER, 'zone' => $name, 'zoneid' => $this->addTemplate($name)]);
    }

    private function addGroupCommand(string $name): PromiseInterface
    {
        if (! $this->validName($name)) {
            return $this->xml('ERROR', 'Gruppens navn er ugyldigt');
        }
        if ($this->hasGroup($name)) {
            return $this->xml('ERROR', 'Gruppen eksisterer allerede');
        }
        $this->addGroup($name);

        // Unlike addzone and addtemplate, QuickDNS does not answer with the new id.
        return $this->xml('OK', 'Gruppen er oprettet');
    }

    private function deleteTemplateCommand(int $id): PromiseInterface
    {
        foreach ($this->zones as $zone) {
            if (in_array($id, $zone['templates'], true)) {
                // What quickdns.dk does (checked 2026-09-22): an HTTP 500, and the template stays.
                return new FulfilledPromise(new Response(500, ['Content-Type' => 'text/html'], 'Internal Server Error'));
            }
        }

        return $this->deleteCommand($this->templates, $id, 'Skabelonen er slettet', 'Skabelonen findes ikke');
    }

    private function deleteGroupCommand(int $id): PromiseInterface
    {
        // quickdns.dk deletes a group that zones are in, and takes it off those zones.
        foreach ($this->zones as $zoneId => $zone) {
            $this->zones[$zoneId]['groups'] = array_values(array_diff($zone['groups'], [$id]));
        }

        return $this->deleteCommand($this->groups, $id, 'Gruppen er slettet', 'Gruppen findes ikke');
    }

    private function deleteCommand(array &$items, int $id, string $ok, string $missing): PromiseInterface
    {
        if (! isset($items[$id])) {
            return $this->xml('ERROR', $missing);
        }
        unset($items[$id]);

        return $this->xml('OK', $ok);
    }

    /**
     * updatetemplates / updategroups: set a zone's templates or groups to exactly the ones given,
     * none when the parameter is left out.
     */
    private function updateCommand(string $field, array $query, string $parameter, array $known): PromiseInterface
    {
        $zone = (int) ($query['zone'] ?? 0);
        if (! isset($this->zones[$zone])) {
            return $this->xml('ERROR', 'Zonen findes ikke');
        }
        $ids = array_map('intval', (array) ($query[$parameter] ?? []));
        foreach ($ids as $id) {
            if (! isset($known[$id])) {
                return $this->xml('ERROR', ($field === 'templates' ? 'Skabelonen' : 'Gruppen').' findes ikke');
            }
        }
        $this->zones[$zone][$field] = $ids;
        $this->zones[$zone]['updated'] = $this->now();

        return $this->xml('OK', 'Opdateret');
    }

    private function page(string $title, string $content, bool $loggedIn = true, array $headers = []): PromiseInterface
    {
        $menu = $loggedIn ? '<a href="/zones">DNS</a><br /><a href="/logout">Log ud</a><br />' : '<a href="/newuser">Opret bruger</a>';
        $html = '<?xml version="1.0" encoding="iso-8859-1"?>'."\n"
            .'<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">'."\n"
            .'<html xmlns="http://www.w3.org/1999/xhtml"><head><title>QuickDNS.dk - '.$title.'</title>'
            .'<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" /></head>'
            .'<body><div class="menu" id="menu"><p>'.$menu.'</p></div><div class="content" id="contentdiv">'.$content.'</div></body></html>';

        return new FulfilledPromise(new Response(200, ['Content-Type' => 'text/html'] + $headers, mb_convert_encoding($html, 'ISO-8859-1', 'UTF-8')));
    }

    private function xml(string $status, string $statustext, array $fields = []): PromiseInterface
    {
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>'."\n<response>\n<status>".$status."</status>\n<statustext>".$this->e($statustext)."</statustext>\n";
        foreach ($fields as $name => $value) {
            $xml .= "<$name>".$this->e((string) $value)."</$name>\n";
        }
        $xml .= "\n</response>\n";

        return new FulfilledPromise(new Response(200, ['Content-Type' => 'text/xml'], mb_convert_encoding($xml, 'ISO-8859-1', 'UTF-8')));
    }

    /**
     * Parse a query string, keeping repeated keys (template=1&template=2) as a list, as QuickDNS'
     * own pages send them.
     */
    private function query(string $query): array
    {
        $values = [];
        foreach (array_filter(explode('&', $query)) as $pair) {
            [$key, $value] = array_map('urldecode', explode('=', $pair, 2) + [1 => '']);
            if (isset($values[$key])) {
                $values[$key] = array_merge((array) $values[$key], [$value]);
            } else {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function sessionOf(RequestInterface $request): ?string
    {
        return preg_match('/PHPSESSID=([0-9a-f]+)/', $request->getHeaderLine('Cookie'), $match) ? $match[1] : null;
    }

    /**
     * The ids as the zone row's onclick carries them: new Array('17284', '17285').
     *
     * @param  int[]  $ids
     */
    private function idArray(array $ids): string
    {
        return 'new Array('.implode(', ', array_map(fn ($id) => "'".$id."'", $ids)).')';
    }

    private function names(array $ids, array $items): string
    {
        return $ids ? implode(', ', array_map(fn ($id) => $this->e($items[$id]['name']), $ids)) : 'Ingen';
    }

    private function zoneId(string $domain): int
    {
        return $this->findZone($domain) ?? throw new \InvalidArgumentException('The fake has no zone '.$domain);
    }

    private function findZone(string $domain): ?int
    {
        foreach ($this->zones as $id => $zone) {
            if ($zone['domain'] === $domain) {
                return $id;
            }
        }

        return null;
    }

    private function templateId(string $name): int
    {
        return $this->find($this->templates, $name) ?? throw new \InvalidArgumentException('The fake has no template '.$name);
    }

    private function groupId(string $name): int
    {
        return $this->find($this->groups, $name) ?? throw new \InvalidArgumentException('The fake has no group '.$name);
    }

    private function find(array $items, string $name): ?int
    {
        foreach ($items as $id => $item) {
            if ($item['name'] === $name) {
                return $id;
            }
        }

        return null;
    }

    /**
     * quickdns.dk accepts letters, digits, "-", "_" and "." in template and group names, Danish
     * letters included, and rejects anything outside ISO-8859-1 (checked 2026-09-22).
     */
    private function validName(string $name): bool
    {
        return preg_match('/^[\p{L}\p{N}_.-]+$/u', $name)
            && mb_convert_encoding(mb_convert_encoding($name, 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1') === $name;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
