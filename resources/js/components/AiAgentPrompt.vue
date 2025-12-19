<script type="text/ecmascript-6">
export default {
    props: ['entry', 'resource'],

    data() {
        return {
            currentMainTab: 'quick',
            currentQuickSubTab: 'curl',
            aiQuestion: '',
            aiResponse: '',
            aiLoading: false,
        };
    },

    computed: {
        baseUrl() {
            return window.location.origin + Telescope.basePath;
        },

        csrfToken() {
            const token = document.head.querySelector('meta[name="csrf-token"]');
            return token ? token.content : 'YOUR_CSRF_TOKEN';
        },

        curlCommand() {
            return `curl '${this.baseUrl}/telescope-api/${this.resource}/${this.entry.id}' \\
  -H 'Accept: application/json' \\
  -H 'X-CSRF-TOKEN: ${this.csrfToken}' \\
  --cookie 'your_session_cookie_here'

# Note: Replace 'your_session_cookie_here' with your actual session cookie
# The cookie name is typically: laravel_session or XSRF-TOKEN + your_app_session`;
        },

        tagQueries() {
            if (!this.entry.tags || !this.entry.tags.length) {
                return 'No tags available for this entry.';
            }

            return this.entry.tags.map(tag => {
                const encodedTag = encodeURIComponent(tag);
                return `# Query entries by tag: ${tag}
curl '${this.baseUrl}/telescope-api/${this.resource}?tag=${encodedTag}&before=&take=50&family_hash=' \\
  -X 'POST' \\
  -H 'Accept: application/json' \\
  -H 'X-CSRF-TOKEN: ${this.csrfToken}' \\
  --cookie 'your_session_cookie_here'`;
            }).join('\n\n');
        },

        fullDataJson() {
            return JSON.stringify(this.entry, null, 2);
        },
    },

    methods: {
        async askAi() {
            if (!this.aiQuestion.trim()) return;

            this.aiLoading = true;
            this.aiResponse = '';

            try {
                const response = await fetch(Telescope.basePath + '/telescope-api/ai/ask', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'text/event-stream',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({
                        entry_type: this.resource,
                        entry_id: this.entry.id,
                        question: this.aiQuestion,
                        context: this.entry,
                    }),
                });

                const reader = response.body.getReader();
                const decoder = new TextDecoder();

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    const chunk = decoder.decode(value);
                    const lines = chunk.split('\n');

                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            const data = line.slice(6);
                            if (data === '[DONE]') continue;
                            try {
                                const parsed = JSON.parse(data);
                                if (parsed.content) {
                                    this.aiResponse += parsed.content;
                                }
                            } catch (e) {
                                // Non-JSON data, append as-is
                                this.aiResponse += data;
                            }
                        }
                    }
                }
            } catch (error) {
                this.aiResponse = 'Error: ' + error.message + '\n\nMake sure AI is configured in telescope.ai config.';
            } finally {
                this.aiLoading = false;
            }
        },
    },
}
</script>

<template>
    <div class="card mt-5 overflow-hidden">
        <div class="card-header">
            <h5 class="m-0">AI Agent Integration</h5>
        </div>

        <ul class="nav nav-pills">
            <li class="nav-item">
                <a class="nav-link" :class="{ active: currentMainTab === 'quick' }" href="#" @click.prevent="currentMainTab = 'quick'">Quick Access</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="{ active: currentMainTab === 'full' }" href="#" @click.prevent="currentMainTab = 'full'">Full Data</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="{ active: currentMainTab === 'ai' }" href="#" @click.prevent="currentMainTab = 'ai'">Ask AI</a>
            </li>
        </ul>

        <!-- Quick Access Tab -->
        <div v-show="currentMainTab === 'quick'">
            <div class="px-3 pt-2">
                <div class="btn-group btn-group-sm mb-2">
                    <button class="btn" :class="currentQuickSubTab === 'curl' ? 'btn-primary' : 'btn-outline-secondary'" @click="currentQuickSubTab = 'curl'">CURL Command</button>
                    <button class="btn" :class="currentQuickSubTab === 'tags' ? 'btn-primary' : 'btn-outline-secondary'" @click="currentQuickSubTab = 'tags'">Tag Queries</button>
                </div>
            </div>

            <div class="code-bg p-4 mb-0 text-white">
                <copy-clipboard :data="currentQuickSubTab === 'curl' ? curlCommand : tagQueries">
                    <pre class="mb-0" style="white-space: pre-wrap; word-break: break-all;">{{ currentQuickSubTab === 'curl' ? curlCommand : tagQueries }}</pre>
                </copy-clipboard>
            </div>
        </div>

        <!-- Full Data Tab -->
        <div v-show="currentMainTab === 'full'">
            <div class="p-3 bg-light border-bottom">
                <small class="text-muted">
                    This data is available at: <code>GET {{ baseUrl }}/telescope-api/{{ resource }}/{{ entry.id }}</code>
                </small>
            </div>
            <div class="code-bg p-4 mb-0 text-white" style="max-height: 500px; overflow-y: auto;">
                <copy-clipboard :data="fullDataJson">
                    <pre class="mb-0" style="white-space: pre-wrap;">{{ fullDataJson }}</pre>
                </copy-clipboard>
            </div>
        </div>

        <!-- Ask AI Tab -->
        <div v-show="currentMainTab === 'ai'" class="p-4">
            <div class="form-group">
                <label for="aiQuestion">Ask a question about this entry:</label>
                <textarea
                    id="aiQuestion"
                    class="form-control"
                    v-model="aiQuestion"
                    rows="3"
                    placeholder="e.g., What could be causing this error? How can I optimize this query?"
                ></textarea>
            </div>
            <button class="btn btn-primary mb-3" @click="askAi" :disabled="aiLoading || !aiQuestion.trim()">
                <span v-if="aiLoading">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin mr-1" style="width: 16px; height: 16px;">
                        <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                    </svg>
                    Thinking...
                </span>
                <span v-else>Ask AI</span>
            </button>

            <div v-if="aiResponse" class="code-bg p-4 text-white" style="max-height: 400px; overflow-y: auto;">
                <pre class="mb-0" style="white-space: pre-wrap;">{{ aiResponse }}</pre>
            </div>

            <div v-if="!aiResponse && !aiLoading" class="alert alert-info mb-0">
                <strong>Note:</strong> AI features require configuration. Set <code>TELESCOPE_AI_ENABLED=true</code> and configure your API key in your <code>.env</code> file. See the <code>telescope.ai</code> config section.
            </div>
        </div>
    </div>
</template>
