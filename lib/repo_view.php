<?php
/**
 * Приведение ответов GitHub API к компактному виду для фронтенда.
 * Никакие лишние поля (и тем более токены) наружу не попадают.
 */

namespace Ghm;

final class RepoView
{
    /**
     * @param array $repo Ответ API /repos/... или /user/repos
     * @param array $extra Дополнительные поля (например, признак локальной копии)
     *
     * @return array
     */
    public static function fromApi(array $repo, array $extra = array())
    {
        $owner = isset($repo['owner']['login']) ? (string) $repo['owner']['login'] : '';

        $view = array(
            'id'             => isset($repo['id']) ? (int) $repo['id'] : 0,
            'name'           => isset($repo['name']) ? (string) $repo['name'] : '',
            'full_name'      => isset($repo['full_name']) ? (string) $repo['full_name'] : trim($owner . '/' . (isset($repo['name']) ? $repo['name'] : ''), '/'),
            'owner'          => $owner,
            'description'    => isset($repo['description']) && $repo['description'] !== null ? (string) $repo['description'] : '',
            'private'        => !empty($repo['private']),
            'fork'           => !empty($repo['fork']),
            'archived'       => !empty($repo['archived']),
            'disabled'       => !empty($repo['disabled']),
            'template'       => !empty($repo['is_template']),
            'language'       => isset($repo['language']) && $repo['language'] !== null ? (string) $repo['language'] : '',
            'language_color' => Format::languageColor(isset($repo['language']) ? (string) $repo['language'] : ''),
            'stars'          => isset($repo['stargazers_count']) ? (int) $repo['stargazers_count'] : 0,
            'forks_count'    => isset($repo['forks_count']) ? (int) $repo['forks_count'] : 0,
            'open_issues'    => isset($repo['open_issues_count']) ? (int) $repo['open_issues_count'] : 0,
            'watchers'       => isset($repo['watchers_count']) ? (int) $repo['watchers_count'] : 0,
            'size_kb'        => isset($repo['size']) ? (int) $repo['size'] : 0,
            'size_human'     => Format::bytes(isset($repo['size']) ? (int) $repo['size'] : 0),
            'default_branch' => isset($repo['default_branch']) && $repo['default_branch'] !== ''
                ? (string) $repo['default_branch'] : 'main',
            'html_url'       => isset($repo['html_url']) ? (string) $repo['html_url'] : '',
            'created_at'     => isset($repo['created_at']) ? (int) strtotime((string) $repo['created_at']) : 0,
            'updated_at'     => isset($repo['updated_at']) ? (int) strtotime((string) $repo['updated_at']) : 0,
            'pushed_at'      => isset($repo['pushed_at']) ? (int) strtotime((string) $repo['pushed_at']) : 0,
            'topics'         => self::topics(isset($repo['topics']) ? $repo['topics'] : array()),
            'can_push'       => !empty($repo['permissions']['push']),
            'can_delete'     => !empty($repo['permissions']['admin']),
            'homepage'       => isset($repo['homepage']) && $repo['homepage'] !== null ? (string) $repo['homepage'] : '',
        );

        $view['updated_human'] = Format::timeAgo($view['updated_at']);
        $view['pushed_human'] = Format::timeAgo($view['pushed_at']);
        $view['is_empty'] = $view['size_kb'] === 0 && $view['pushed_at'] === 0;

        // Родитель для форков: /repos/... отдаёт parent и source.
        $parent = null;
        if (!empty($repo['parent']['full_name'])) {
            $parent = array(
                'full_name' => (string) $repo['parent']['full_name'],
                'html_url'  => isset($repo['parent']['html_url']) ? (string) $repo['parent']['html_url'] : '',
            );
        } elseif (!empty($repo['source']['full_name'])) {
            $parent = array(
                'full_name' => (string) $repo['source']['full_name'],
                'html_url'  => isset($repo['source']['html_url']) ? (string) $repo['source']['html_url'] : '',
            );
        }
        $view['fork_parent'] = $parent;

        foreach ($extra as $key => $value) {
            $view[$key] = $value;
        }

        return $view;
    }

    /** @return array<int,string> */
    private static function topics($topics)
    {
        if (!is_array($topics)) {
            return array();
        }

        $clean = array();
        foreach (array_slice($topics, 0, 12) as $topic) {
            if (is_string($topic) && $topic !== '') {
                $clean[] = mb_substr($topic, 0, 40, 'UTF-8');
            }
        }

        return $clean;
    }

    /**
     * Компактная информация о ветке.
     *
     * @return array
     */
    public static function branch(array $branch, $defaultBranch = '')
    {
        $sha = isset($branch['commit']['sha']) ? (string) $branch['commit']['sha'] : '';
        $name = isset($branch['name']) ? (string) $branch['name'] : '';

        return array(
            'name'       => $name,
            'sha'        => $sha,
            'short_sha'  => $sha !== '' ? substr($sha, 0, 7) : '',
            'protected'  => !empty($branch['protected']),
            'is_default' => $name !== '' && $name === $defaultBranch,
            'author'     => isset($branch['commit']['commit']['author']['name'])
                ? (string) $branch['commit']['commit']['author']['name'] : '',
            'date'       => isset($branch['commit']['commit']['author']['date'])
                ? (int) strtotime((string) $branch['commit']['commit']['author']['date']) : 0,
        );
    }
}
