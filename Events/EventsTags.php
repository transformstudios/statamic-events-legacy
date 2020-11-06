<?php

namespace Statamic\Addons\Events;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\CalendarLinks\Link;
use Statamic\Addons\Collection\CollectionTags;
use Statamic\API\Arr;
use Statamic\API\Request;
use Statamic\API\URL;
use Statamic\Presenters\PaginationPresenter;

class EventsTags extends CollectionTags
{
    /** @var Events */
    private $events;

    /** @var Collection */
    private $dates;

    private $paginationData;

    public function __construct()
    {
        parent::__construct();

        $this->dates = collect();
        $this->events = new Events();

        Carbon::setWeekStartsAt(Carbon::SUNDAY);
        Carbon::setWeekEndsAt(Carbon::SATURDAY);
    }

    public function upcoming()
    {
        $this->limit = $this->getInt('limit', 1);
        $this->offset = $this->getInt('offset', 0);

        $this->loadEvents($this->getBool('collapse_multi_days', false));

        if ($this->getBool('paginate')) {
            $this->paginate();
        } else {
            $this->dates = $this->events->upcoming($this->limit, $this->offset);
        }

        return $this->generateOutput();
    }

    public function calendar()
    {
        $calendar = new Calendar($this->getParam('collection', $this->getConfig('events_collection')));

        return $this->parseLoop($calendar->month($this->getParam('month'), $this->getParam('year')));
    }

    public function in()
    {
        $this->loadEvents($this->getBool('collapse_multi_days', false));

        $from = Carbon::now()->startOfDay();
        $to = Carbon::now()->modify($this->getParam('next'))->endOfDay();

        $this->loadDates($from, $to);

        return $this->parseLoop(
            array_merge(
                $this->makeEmptyDates($from, $to),
                $this->dates->toArray()
            )
        );
    }

    public function downloadLink()
    {
        $event = EventFactory::createFromArray($this->context);

        $from = $event->start();
        $to = $event->end();

        if ($event->isRecurring()) {
            $from->setDateFrom(carbon($this->getParam('date')));
            $to = $from->copy()->setTimeFromTimeString($event->endTime());
        }

        $title = Arr::get($this->context, 'title');
        $allDay = Arr::get($this->context, 'all_day', false);
        $location = Arr::get($this->context, 'location', '');

        $type = $this->getParam('type');

        $link = Link::create($title, $from, $to, $allDay)->address($location);

        return $link->$type();
    }

    public function nowOrParam()
    {
        $monthYear = request('month', Carbon::now()->englishMonth).' '.request('year', Carbon::now()->year);

        $month = carbon($monthYear);

        if ($modify = $this->getParam('modify')) {
            $month->modify($modify);
        }

        return $month->format($this->getParam('format'));
    }

    protected function paginate()
    {
        $this->paginated = true;

        $page = (int) Request::get('page', 1);

        $this->offset = (($page - 1) * $this->limit) + $this->offset;

        $events = $this->events->upcoming($this->limit + 1, $this->offset);

        $count = $this->events->count();

        $paginator = new LengthAwarePaginator(
            $events,
            $count,
            $this->limit,
            $page
        );

        $paginator->setPath(URL::makeAbsolute(URL::getCurrent()));
        $paginator->appends(Request::all());

        $this->paginationData = [
            'total_items'    => $count,
            'items_per_page' => $this->limit,
            'total_pages'    => $paginator->lastPage(),
            'current_page'   => $paginator->currentPage(),
            'prev_page'      => $paginator->previousPageUrl(),
            'next_page'      => $paginator->nextPageUrl(),
            'auto_links'     => $paginator->render(),
            'links'          => $paginator->render(new PaginationPresenter($paginator)),
        ];

        $this->dates = $events->slice(0, $this->limit);
    }

    protected function generateOutput()
    {
        $data = array_merge(
            $this->getEventsMetaData(),
            ['dates' => $this->dates->toArray()]
        );

        if ($this->paginationData) {
            $data = array_merge($data, ['pagination' => $this->paginationData]);
        }

        return $this->parse($data);
    }

    private function loadDates($from, $to)
    {
        $this->dates = $this->events
            ->all($from, $to)
            ->groupBy(function ($event, $key) {
                return $event->start_date;
            })
            ->map(function ($days, $key) {
                return [
                    'date' => $key,
                    'dates' => $days->toArray(),
                ];
            });
    }

    private function loadEvents(bool $collapseMultiDays = false)
    {
        $this->parameters['show_future'] = true;

        // Need to "remove" the limit & paginate, otherwise the `collect` below will limit & paginate the entries.
        // We need to get all the entries, then make the events AND THEN limit & paginate.
        // Didn't use a new parameter because that would break all existing instances and
        // would be a much larger code change.
        // @TODO refactor when move to v3
        if ($limit = $this->getInt('limit')) {
            unset($this->parameters['limit']);
        }

        if ($paginate = $this->getBool('paginate')) {
            unset($this->parameters['paginate']);
        }

        $this->collect($this->get('collection'));

        if ($limit) {
            $this->limit = $this->parameters['limit'] = $limit;
        }

        if ($paginate) {
            $this->parameters['paginate'] = $paginate;
        }

        $this->collection->each(
            function ($event) use ($collapseMultiDays) {
                $this->events->add(
                    EventFactory::createFromArray(
                        array_merge(
                            $event->toArray(),
                            [
                                'asSingleDay' => $collapseMultiDays,
                            ]
                        )
                    )
                );
            }
        );
    }

    private function makeEmptyDates($from, $to): array
    {
        $dates = [];
        $currentDay = $from;

        foreach (range(0, $to->diffInDays($from)) as $ignore) {
            $date = $currentDay->toDateString();
            $dates[$date] = [
                'date' => $date,
                'no_results' => true,
            ];
            $currentDay->addDay();
        }

        return $dates;
    }

    /**
     * Get any meta data that should be available in templates.
     *
     * @return array
     */
    protected function getEventsMetaData()
    {
        return [
            'total_results' => $this->dates->count(),
        ];
    }
}
