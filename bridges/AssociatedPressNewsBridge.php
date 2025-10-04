<?php

class AssociatedPressNewsBridge extends BridgeAbstract
{
    const NAME = 'Associated Press News Bridge';
    const URI = 'https://apnews.com/';
    const DESCRIPTION = 'Returns newest articles by topic';
    const MAINTAINER = 'VerifiedJoseph';
    const PARAMETERS = [
        'Standard Topics' => [
            'topic' => [
                'name' => 'Topic',
                'type' => 'list',
                'values' => [
                    'AP Top News' => 'apf-topnews',
                    'World News' => 'world-news',
                    'U.S. News' => 'us-news',
                    'Politics' => 'politics',
                    'Sports' => 'sports',
                    'Entertainment' => 'entertainment',
                    'Oddities' => 'oddities',
                    'Travel' => 'travel',
                    'Technology' => 'technology',
                    'Lifestyle' => 'lifestyle',
                    'Business' => 'business',
                    'Health' => 'health',
                    'Science' => 'science',
                    'Religion' => 'religion',
                    'Fact Checks' => 'ap-fact-check',
                ],
                'defaultValue' => 'apf-topnews',
            ],
        ],
        'Custom Topic' => [
            'topic' => [
                'name' => 'Topic',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'europe'
            ],
        ]
    ];

    const CACHE_TIMEOUT = 900; // 15 mins

    private $feedName = '';

    public function collectData()
    {
        $topic = $this->getInput('topic');
        // The new API endpoint
        $apiUrl = 'https://apnews.com/graphql';

        // GraphQL query to fetch the feed
        $query = 'query GetFeed($tag: String, $pageSize: Int, $offset: Int) {
            feed(tag: $tag, first: $pageSize, offset: $offset) {
                items {
                    ... on Content {
                        id
                        shortId
                        headline
                        bylines
                        published
                        storyHtml
                        siteId
                        firstWords
                        route {
                            path
                        }
                        primaryImage {
                            ... on Image {
                                gcsUrl
                            }
                        }
                    }
                }
                tag {
                    name
                }
            }
        }';

        $variables = [
            'tag' => $topic,
            'pageSize' => 20,
            'offset' => 0
        ];

        $payload = json_encode([
            'query' => $query,
            'variables' => $variables
        ]);

        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ];

        $json = getContents($apiUrl, $headers, $payload);
        $data = json_decode($json, true);

        if (empty($data['data']['feed']['items'])) {
            throwClientException('Topic not found or no articles for: ' . $topic);
        }

        $this->feedName = $data['data']['feed']['tag']['name'] ?? ucfirst(str_replace('-', ' ', $topic));

        foreach ($data['data']['feed']['items'] as $card) {
            $item = [];

            if (empty($card['storyHtml'])) {
                continue;
            }

            // Construct URI from route path or fallback to shortId
            if (isset($card['route']['path'])) {
                $item['uri'] = self::URI . ltrim($card['route']['path'], '/');
            } else {
                $item['uri'] = self::URI . 'article/' . $card['shortId'];
            }

            $item['title'] = $card['headline'];
            $item['timestamp'] = $card['published'];
            $item['content'] = defaultLinkTo($card['storyHtml'], self::URI);

            if (!empty($card['bylines'])) {
                $author = str_replace('By ', '', $card['bylines']);
                $item['author'] = ucwords(strtolower($author));
            }

            // Add primary image to content if available
            if (isset($card['primaryImage']['gcsUrl'])) {
                $imageUrl = $card['primaryImage']['gcsUrl'] . 'alternates/LANDSCAPE_16_9/1000.jpg';
                $item['content'] = '<p><img src="' . $imageUrl . '"></p>' . $item['content'];
                $item['enclosures'][] = $imageUrl;
            }

            $this->items[] = $item;

            if (count($this->items) >= 15) {
                break;
            }
        }
    }

    public function getURI()
    {
        if (!is_null($this->getInput('topic'))) {
            return self::URI . 'hub/' . $this->getInput('topic');
        }

        return parent::getURI();
    }

    public function getName()
    {
        if (!empty($this->feedName)) {
            return $this->feedName . ' - Associated Press';
        }

        return parent::getName();
    }
}
