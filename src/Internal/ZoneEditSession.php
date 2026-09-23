<?php

namespace QuickDns\Internal;

use QuickDns\Exceptions\RecordRejected;
use QuickDns\Exceptions\UnrecognisedPage;
use QuickDns\Parsing\ZoneTable;
use QuickDns\QuickDns;
use QuickDns\Record;
use Symfony\Component\DomCrawler\Crawler;

/**
 * An edit session on one zone.
 *
 * QuickDNS keeps the changes server side until editzonedone: submitzonechange applies one change
 * at a time to a pending copy of the table, and answers with what it did to which row. This class
 * mirrors that table so a record can be addressed by the row it sits on right now.
 *
 * @internal
 */
final class ZoneEditSession
{
    /**
     * Every row of the table, header included, so the index is the row number QuickDNS uses.
     * A row that holds no record is null.
     *
     * @var array<int, ?Record>
     */
    private array $rows = [];

    private int $sequence = 0;

    private bool $started = false;

    private bool $finished = false;

    private ?int $lastChangedRow = null;

    private function __construct(
        private readonly QuickDns $quickDns,
        private readonly string $zoneId,
        private readonly string $key,
    ) {
    }

    /**
     * @throws UnrecognisedPage when the page is not a zone page, or carries no session key
     */
    public static function open(QuickDns $quickDns, string $zoneId, Crawler $page): self
    {
        $table = ZoneTable::fromPage($page, 'zone '.$zoneId);

        // The session key sits in the page's onload: init('<64 hex>', false).
        if (! preg_match("/init\(\s*'([0-9a-f]{8,})'/", $page->html(), $match)) {
            throw new UnrecognisedPage('No edit session key on the zone page for zone '.$zoneId);
        }

        $session = new self($quickDns, $zoneId, $match[1]);
        $session->rows = $table->byRow();

        return $session;
    }

    /**
     * The records in the pending table, in row order.
     *
     * @return Record[]
     */
    public function records(): array
    {
        return array_values(array_filter($this->rows));
    }

    public function rowOf(Record $record): ?int
    {
        $row = array_search($record, $this->rows, true);

        return $row === false ? null : $row;
    }

    /**
     * Apply one change. The caller has already validated the record.
     *
     * @param  array<string, string|int>  $parameters
     *
     * @throws RecordRejected when QuickDNS says no, which spends the whole session
     */
    public function change(array $parameters, ?Record $attempted = null): void
    {
        $this->start();
        $this->lastChangedRow = null;
        $response = ZoneChangeResponse::fromXml($this->quickDns->xml('submitzonechange', [
            'seq' => ++$this->sequence,
            'zkey' => $this->key,
        ] + $parameters));

        foreach ($response->actions as $action) {
            $this->apply($action);
        }

        if ($response->rejected()) {
            $records = array_values(array_filter(array_map(fn (int $row) => $this->rows[$row] ?? null, $response->badRows)));
            throw new RecordRejected(
                $response->errors[0] ?? $response->status,
                $response->status,
                $response->errors,
                $response->badRows,
                $records,
                $attempted,
            );
        }
    }

    /**
     * The row a record sits on after the last change, so the caller can hand back the live object.
     */
    public function record(int $row): ?Record
    {
        return $this->rows[$row] ?? null;
    }

    /**
     * The row the last change touched: QuickDNS reports it, we do not compute it.
     */
    public function lastChangedRow(): ?int
    {
        return $this->lastChangedRow;
    }

    /**
     * Commit the session. QuickDNS wants the sequence number of the last change, not the next one:
     * with the next one it answers with an ordinary page and saves nothing.
     */
    public function save(): void
    {
        if (! $this->started || $this->finished) {
            $this->finished = true;

            return;
        }
        $page = $this->quickDns->request('editzonedone', ['save' => 1, 'seq' => $this->sequence, 'zkey' => $this->key]);
        $this->finished = true;
        if (! str_contains($page, 'Log ud')) {
            throw new UnrecognisedPage('Unexpected page after saving zone '.$this->zoneId);
        }
    }

    /**
     * Throw the session away. Never throws: it runs while another exception is on its way out.
     */
    public function close(): void
    {
        if (! $this->started || $this->finished) {
            return;
        }
        $this->finished = true;
        try {
            $this->quickDns->request('editzonedone', ['save' => 0, 'seq' => $this->sequence, 'zkey' => $this->key]);
        } catch (\Throwable) {
            // An abandoned session leaves nothing behind, so a failed discard changes nothing.
        }
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->quickDns->xml('submitzonechange', ['action' => 'initial', 'seq' => 0, 'zkey' => $this->key]);
    }

    private function apply(ZoneAction $action): void
    {
        switch ($action->action) {
            case ZoneAction::INSERT:
                array_splice($this->rows, $action->row, 0, [null]);
                break;
            case ZoneAction::CHANGE:
                $existing = $this->rows[$action->row] ?? null;
                // The answer has no template name, so carry it over from the row being changed.
                $this->rows[$action->row] = new Record(
                    $action->record->name,
                    $action->record->type,
                    $action->record->ttl,
                    $action->record->priority,
                    $action->record->value,
                    $action->row,
                    $existing?->template,
                );
                $this->lastChangedRow = $action->row;
                break;
            case ZoneAction::DELETE:
                array_splice($this->rows, $action->row, 1);
                break;
        }
    }
}
