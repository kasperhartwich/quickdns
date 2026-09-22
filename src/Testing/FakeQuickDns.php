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
            'addzone' => $this->addZoneCommand((string) ($query['zone'] ?? '')),
            'delzone' => $this->deleteCommand($this->zones, (int) ($query['id'] ?? 0), 'Zonen er slettet', 'Zonen findes ikke'),
            'addtemplate' => $this->addTemplateCommand((string) ($query['zone'] ?? '')),
            'deltemplate' => $this->deleteCommand($this->templates, (int) ($query['id'] ?? 0), 'Skabelonen er slettet', 'Skabelonen findes ikke'),
            'addgroup' => $this->addGroupCommand((string) ($query['group'] ?? '')),
            'delgroup' => $this->deleteCommand($this->groups, (int) ($query['id'] ?? 0), 'Gruppen er slettet', 'Gruppen findes ikke'),
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
                .'<td class="listrow2"><a href="javascript:void(0);">'.$this->names($zone['templates'], $this->templates).'</a></td>'
                .'<td class="listrow2"><a href="javascript:void(0);">'.$this->names($zone['groups'], $this->groups).'</a></td>'
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

        return $this->page('Mine zoner', '<p>Zone: '.$this->e($this->zones[$id]['domain']).'</p>'
            .'<table class="listtable records" id="zone_table"><tr class="listheader">'
            .'<th class="listheader">Record</th><th class="listheader">TTL</th><th class="listheader">Type</th>'
            .'<th class="listheader">Prioritet</th><th class="listheader">Værdi</th><th class="listheader">Ret</th><th class="listheader">Slet</th>'
            ."</tr>\n".$rows.'</table>');
    }

    private function addZoneCommand(string $domain): PromiseInterface
    {
        if (! preg_match('/^(?=.{1,253}$)([a-z0-9æøå](-*[a-z0-9æøå])*\.)+(dk|com|net|org|eu|se|no|de|io|nu|info)$/iu', $domain)) {
            return $this->xml('ERROR', 'Zonens navn er ugyldigt', ['zone' => $domain]);
        }
        if ($this->hasZone($domain)) {
            return $this->xml('ERROR', 'Zonen eksisterer allerede', ['zone' => $domain]);
        }

        return $this->xml('OK', 'Zonen er oprettet', ['zone' => $domain, 'zoneid' => $this->addZone($domain)]);
    }

    private function addTemplateCommand(string $name): PromiseInterface
    {
        if (! preg_match('/^[\w.-]+$/u', $name)) {
            return $this->xml('ERROR', 'Skabelonens navn er ugyldigt', ['zone' => $name]);
        }
        if ($this->hasTemplate($name)) {
            return $this->xml('ERROR', 'Skabelonen eksisterer allerede', ['zone' => $name]);
        }

        // QuickDNS answers with the template's id in <zoneid>.
        return $this->xml('OK', 'Skabelonen er oprettet', ['zone' => $name, 'zoneid' => $this->addTemplate($name)]);
    }

    private function addGroupCommand(string $name): PromiseInterface
    {
        if (! preg_match('/^[\w.-]+$/u', $name)) {
            return $this->xml('ERROR', 'Gruppens navn er ugyldigt');
        }
        if ($this->hasGroup($name)) {
            return $this->xml('ERROR', 'Gruppen eksisterer allerede');
        }
        $this->addGroup($name);

        // Unlike addzone and addtemplate, QuickDNS does not answer with the new id.
        return $this->xml('OK', 'Gruppen er oprettet');
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

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
