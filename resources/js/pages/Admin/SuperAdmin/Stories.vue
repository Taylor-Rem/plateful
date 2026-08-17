<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/components/admin/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { update as storiesUpdate } from '@/routes/admin/super/stories';
import SuperAdminLayout from '@/layouts/admin/SuperAdminLayout.vue';

type StoryRow = {
    slug: string;
    title: string;
    date: string;
    tags: string[];
    filePublished: boolean;
    overridePublished: boolean | null;
    isLive: boolean;
    isFutureDated: boolean;
    url: string;
};

defineOptions({ layout: SuperAdminLayout });

defineProps<{
    stories: StoryRow[];
}>();

function setPublished(story: StoryRow, published: boolean): void {
    router.put(
        storiesUpdate.url(story.slug),
        { published },
        { preserveScroll: true },
    );
}

function effectivePublished(story: StoryRow): boolean {
    return story.overridePublished ?? story.filePublished;
}
</script>

<template>
    <div>
        <Head title="Stories" />
        <div class="space-y-8">
            <PageHeader
                title="Stories"
                description="Publish state for the flat-file stories on the marketing site. Content lives in git (content/stories); a toggle here overrides the file's front matter instantly, without a deploy."
            />

            <div
                class="overflow-x-auto rounded-lg border border-border bg-card"
            >
                <table class="w-full divide-y divide-border">
                    <thead
                        class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3">Story</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Tags</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-sm">
                        <tr v-for="story in stories" :key="story.slug">
                            <td class="px-4 py-3">
                                <a
                                    :href="story.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-medium text-foreground hover:underline"
                                >
                                    {{ story.title }}
                                </a>
                                <p class="text-xs text-muted-foreground">
                                    {{ story.slug }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ story.date }}
                                <span
                                    v-if="story.isFutureDated"
                                    class="ml-1 text-xs text-amber-600"
                                    >(future)</span
                                >
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ story.tags.join(', ') || '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="story.isLive"
                                    class="font-semibold text-primary"
                                    >Live</span
                                >
                                <span
                                    v-else-if="
                                        effectivePublished(story) &&
                                        story.isFutureDated
                                    "
                                    class="text-muted-foreground"
                                    >Scheduled</span
                                >
                                <span v-else class="text-muted-foreground"
                                    >Draft</span
                                >
                                <p
                                    v-if="story.overridePublished !== null"
                                    class="text-xs text-amber-600"
                                >
                                    Overridden here — file says
                                    {{
                                        story.filePublished
                                            ? 'published'
                                            : 'draft'
                                    }}; update the front matter in the next
                                    commit.
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end">
                                    <Button
                                        v-if="!effectivePublished(story)"
                                        size="sm"
                                        @click="setPublished(story, true)"
                                    >
                                        Publish
                                    </Button>
                                    <Button
                                        v-else
                                        variant="outline"
                                        size="sm"
                                        @click="setPublished(story, false)"
                                    >
                                        Unpublish
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="stories.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-6 text-center text-muted-foreground"
                            >
                                No stories found in content/stories.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-muted-foreground">
                Publishing here makes an already-deployed story visible
                immediately (future-dated stories stay hidden until their
                date). It does not edit the markdown file — sync the
                <code>published:</code> front matter in git when convenient,
                and the override clears itself once they agree.
            </p>
        </div>
    </div>
</template>
