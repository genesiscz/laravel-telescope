<script type="text/ecmascript-6">
export default {
    props: ['entry', 'resource'],

    data() {
        return {
            currentTab: 'curl',
            aiQuestion: '',
            aiLoading: false,
            aiConfigured: true, // Will be checked on mount
            conversations: [], // Array of {question, response}
            selectedProvider: 'openai',
            selectedModel: 'gpt-4o-mini',
            providers: {
                'openai': ['gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo'],
                'anthropic': ['claude-3-5-sonnet-20241022', 'claude-3-opus-20240229', 'claude-3-haiku-20240307'],
                'groq': ['llama-3.1-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768'],
                'gemini': ['gemini-1.5-pro', 'gemini-1.5-flash', 'gemini-pro'],
                'mistral': ['mistral-large-latest', 'mistral-medium-latest', 'mistral-small-latest'],
            },
        };
    },

    computed: {
        baseUrl() {
            return window.location.origin + Telescope.basePath;
        },

        csrfToken() {
            const token = document.head.querySelector('meta[name="csrf-token"]');
            return token ? token.content : '';
        },

        sessionCookie() {
            // Get all cookies as a string for the CURL command
            return document.cookie || '';
        },

        curlCommand() {
            return `curl '${this.baseUrl}/telescope-api/${this.resource}/${this.entry.id}' \\
  -H 'Accept: application/json' \\
  -H 'X-CSRF-TOKEN: ${this.csrfToken}' \\
  -H 'Cookie: ${this.sessionCookie}'`;
        },

        tagTabs() {
            if (!this.entry.tags || !this.entry.tags.length) {
                return [];
            }
            return this.entry.tags.map(tag => ({
                tag: tag,
                label: `Query entries for tag ${tag}`,
                command: this.buildTagQuery(tag)
            }));
        },

        fullDataJson() {
            return JSON.stringify(this.entry, null, 2);
        },

        availableModels() {
            return this.providers[this.selectedProvider] || [];
        },

        allTabs() {
            const tabs = [
                { id: 'curl', label: 'CURL Command' },
            ];
            this.tagTabs.forEach((tagTab, index) => {
                tabs.push({
                    id: `tag-${index}`,
                    label: tagTab.label,
                    command: tagTab.command
                });
            });
            tabs.push({ id: 'full', label: 'Full Data' });
            tabs.push({ id: 'ai', label: 'Ask AI' });
            return tabs;
        }
    },

    mounted() {
        this.checkAiConfiguration();
        // Set default model based on provider
        if (this.availableModels.length > 0 && !this.availableModels.includes(this.selectedModel)) {
            this.selectedModel = this.availableModels[0];
        }
    },

    watch: {
        selectedProvider() {
            // Reset model when provider changes
            if (this.availableModels.length > 0) {
                this.selectedModel = this.availableModels[0];
            }
        }
    },

    methods: {
        buildTagQuery(tag) {
            const encodedTag = encodeURIComponent(tag);
            return `curl '${this.baseUrl}/telescope-api/${this.resource}?tag=${encodedTag}&before=&take=50&family_hash=' \\
  -X 'POST' \\
  -H 'Accept: application/json' \\
  -H 'X-CSRF-TOKEN: ${this.csrfToken}' \\
  -H 'Cookie: ${this.sessionCookie}'`;
        },

        async checkAiConfiguration() {
            try {
                const response = await fetch(Telescope.basePath + '/telescope-api/ai/config', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                });
                if (response.ok) {
                    const data = await response.json();
                    this.aiConfigured = data.configured === true;
                    if (data.provider) {
                        this.selectedProvider = data.provider;
                    }
                    if (data.model) {
                        this.selectedModel = data.model;
                    }
                }
            } catch (e) {
                this.aiConfigured = false;
            }
        },

        async fetchFullEntryData() {
            try {
                const response = await fetch(Telescope.basePath + '/telescope-api/' + this.resource + '/' + this.entry.id, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                });
                if (response.ok) {
                    return await response.json();
                }
            } catch (e) {
                console.error('Failed to fetch full entry data:', e);
            }
            return this.entry;
        },

        async askAi() {
            if (!this.aiQuestion.trim()) return;

            this.aiLoading = true;
            const currentQuestion = this.aiQuestion;
            const conversationIndex = this.conversations.length;

            // Add new conversation entry
            this.conversations.push({
                question: currentQuestion,
                response: '',
                loading: true
            });

            // Clear the input
            this.aiQuestion = '';

            try {
                // Fetch full entry data from API (same as controller gives)
                const fullData = await this.fetchFullEntryData();

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
                        question: currentQuestion,
                        context: fullData,
                        provider: this.selectedProvider,
                        model: this.selectedModel,
                    }),
                });

                if (!response.ok) {
                    const errorData = await response.json();
                    this.conversations[conversationIndex].response = 'Error: ' + (errorData.error || 'Unknown error');
                    this.conversations[conversationIndex].loading = false;
                    return;
                }

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
                                    this.conversations[conversationIndex].response += parsed.content;
                                }
                                if (parsed.error) {
                                    this.conversations[conversationIndex].response += '\n\nError: ' + parsed.error;
                                }
                            } catch (e) {
                                // Non-JSON data, skip
                            }
                        }
                    }
                }
            } catch (error) {
                this.conversations[conversationIndex].response = 'Error: ' + error.message;
            } finally {
                this.conversations[conversationIndex].loading = false;
                this.aiLoading = false;
            }
        },

        getTabContent(tab) {
            if (tab.id === 'curl') {
                return this.curlCommand;
            }
            if (tab.id.startsWith('tag-')) {
                return tab.command;
            }
            return '';
        }
    },
}
</script>

<template>
    <div class="card mt-5 overflow-hidden">
        <div class="card-header" style="background: #1a1a2e; border-bottom: 1px solid #333;">
            <h5 class="m-0" style="color: #fff;">AI Agent Integration</h5>
        </div>

        <ul class="nav nav-pills" style="background: #252540; flex-wrap: wrap;">
            <li class="nav-item" v-for="tab in allTabs" :key="tab.id">
                <a
                    class="nav-link"
                    :class="{ active: currentTab === tab.id }"
                    href="#"
                    @click.prevent="currentTab = tab.id"
                    style="color: #fff;"
                    :style="currentTab === tab.id ? 'background: #4a4a6a;' : ''"
                >{{ tab.label }}</a>
            </li>
        </ul>

        <!-- CURL Command Tab -->
        <div v-show="currentTab === 'curl'" class="code-bg p-4 mb-0" style="background: #1a1a2e;">
            <copy-clipboard :data="curlCommand">
                <pre class="mb-0" style="white-space: pre-wrap; word-break: break-all; color: #fff;">{{ curlCommand }}</pre>
            </copy-clipboard>
        </div>

        <!-- Tag Query Tabs -->
        <div v-for="(tab, index) in tagTabs" :key="'tag-content-' + index" v-show="currentTab === 'tag-' + index" class="code-bg p-4 mb-0" style="background: #1a1a2e;">
            <copy-clipboard :data="tab.command">
                <pre class="mb-0" style="white-space: pre-wrap; word-break: break-all; color: #fff;">{{ tab.command }}</pre>
            </copy-clipboard>
        </div>

        <!-- Full Data Tab -->
        <div v-show="currentTab === 'full'" style="background: #1a1a2e;">
            <div class="p-3" style="border-bottom: 1px solid #333;">
                <small style="color: #aaa;">
                    This data is available at: <code style="color: #7dd3fc;">GET {{ baseUrl }}/telescope-api/{{ resource }}/{{ entry.id }}</code>
                </small>
            </div>
            <div class="p-4" style="max-height: 500px; overflow-y: auto;">
                <copy-clipboard :data="fullDataJson">
                    <pre class="mb-0" style="white-space: pre-wrap; color: #fff;">{{ fullDataJson }}</pre>
                </copy-clipboard>
            </div>
        </div>

        <!-- Ask AI Tab -->
        <div v-show="currentTab === 'ai'" class="p-4" style="background: #1a1a2e;">
            <!-- Configuration warning - only show if not configured -->
            <div v-if="!aiConfigured" class="alert mb-3" style="background: #3d2b1f; border: 1px solid #6b4423; color: #fbbf24;">
                <strong>Note:</strong> AI features require configuration. Set <code style="color: #fbbf24;">TELESCOPE_AI_ENABLED=true</code> and configure your API key in your <code style="color: #fbbf24;">.env</code> file.
            </div>

            <!-- Provider/Model Selection -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label style="color: #fff;" class="mb-1">Provider</label>
                    <select v-model="selectedProvider" class="form-control" style="background: #252540; color: #fff; border-color: #444;">
                        <option v-for="(models, provider) in providers" :key="provider" :value="provider">{{ provider }}</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label style="color: #fff;" class="mb-1">Model</label>
                    <select v-model="selectedModel" class="form-control" style="background: #252540; color: #fff; border-color: #444;">
                        <option v-for="model in availableModels" :key="model" :value="model">{{ model }}</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="aiQuestion" style="color: #fff;">Ask a question about this entry:</label>
                <textarea
                    id="aiQuestion"
                    class="form-control"
                    v-model="aiQuestion"
                    rows="3"
                    placeholder="e.g., What could be causing this error? How can I optimize this query?"
                    style="background: #252540; color: #fff; border-color: #444;"
                    @keydown.meta.enter="askAi"
                    @keydown.ctrl.enter="askAi"
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

            <!-- Conversation History -->
            <div v-for="(conv, index) in conversations" :key="index" class="mb-4">
                <div class="mb-2 p-3" style="background: #252540; border-radius: 8px;">
                    <strong style="color: #7dd3fc;">Q:</strong>
                    <span style="color: #fff;"> {{ conv.question }}</span>
                </div>
                <div class="p-3" style="background: #1e1e3f; border-radius: 8px; border: 1px solid #333;">
                    <strong style="color: #4ade80;">A:</strong>
                    <span v-if="conv.loading" style="color: #aaa;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin mr-1" style="width: 14px; height: 14px; display: inline-block;">
                            <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                        </svg>
                        Thinking...
                    </span>
                    <pre v-else class="mb-0 mt-2" style="white-space: pre-wrap; color: #fff; font-family: inherit;">{{ conv.response }}</pre>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.nav-link {
    border-radius: 0 !important;
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.nav-link:hover {
    background: #3a3a5a !important;
}

.nav-link.active {
    background: #4a4a6a !important;
}
</style>
