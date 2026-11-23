// Alpine components of the panel (E-157). The Content Security Policy
// allows no eval, so Livewire loads its CSP-safe Alpine build. That build
// only understands simple expressions in the markup (properties, calls,
// comparisons); all logic lives here and the markup calls it by name.

const register = (Alpine) => {
    // Tooltip of <x-line-chart> and <x-bar-chart>. The data comes from the
    // server as JSON in data-ow-chart: n points, t labels, v values (one
    // list per line for line charts), e extra values (bar charts).
    // Livewire may replace the data while the component lives on (another
    // time range), so it is read from the attribute whenever it changed.
    Alpine.data('owChart', () => ({
        i: null,
        element: null,
        raw: null,
        data: { n: 0, t: [], v: [], e: [] },

        init() {
            this.element = this.$el;
        },

        get chart() {
            const raw = this.element?.dataset.owChart ?? '{}';
            if (raw !== this.raw) {
                this.raw = raw;
                this.data = { n: 0, t: [], v: [], e: [], ...JSON.parse(raw) };
            }

            return this.data;
        },

        get bar() {
            return this.element?.dataset.owChartMode === 'bar';
        },

        move(event) {
            const { n } = this.chart;
            const rect = event.currentTarget.getBoundingClientRect();
            const x = (event.clientX - rect.left) / rect.width;
            if (this.bar) {
                this.i = Math.max(0, Math.min(n - 1, Math.floor(x * n)));
            } else {
                this.i = n > 1 ? Math.max(0, Math.min(n - 1, Math.round(x * (n - 1)))) : 0;
            }
        },

        leave() {
            this.i = null;
        },

        get active() {
            return this.i !== null;
        },

        // Position of the point between 0 and 1.
        get position() {
            const { n } = this.chart;
            if (this.i === null) {
                return 0;
            }
            if (this.bar) {
                return (this.i + 0.5) / n;
            }

            return n > 1 ? this.i / (n - 1) : 0.5;
        },

        get crossX() {
            return this.position * 1000;
        },

        get tooltipStyle() {
            return `left: ${this.position * 100}%`;
        },

        get tooltipClass() {
            return this.i !== null && this.i > this.chart.n / 2 ? 'ow-chart-tooltip-left' : '';
        },

        get label() {
            return this.i === null ? '' : this.chart.t[this.i];
        },

        // Value of line `series`, or of the bar chart without an argument.
        value(series = null) {
            if (this.i === null) {
                return '';
            }

            return series === null ? this.chart.v[this.i] : this.chart.v[series][this.i];
        },

        get extra() {
            return this.i === null ? '' : this.chart.e[this.i];
        },

        barClass(index) {
            return this.i === index ? 'ow-chart-bar-active' : '';
        },
    }));

    // "Also www.<domain>" when creating a website (E-110, E-120): only
    // offered for main domains like example.de, not for subdomains.
    // Unchecking stays until the user checks it again.
    Alpine.data('owWww', () => ({
        touched: false,
        www: false,
        main: true,
        suffixes: [],

        init() {
            const data = this.$el.dataset;
            this.touched = data.owTouched === '1';
            this.www = data.owWww === '1';
            this.main = data.owMain === '1';
            this.suffixes = JSON.parse(data.owSuffixes || '[]');
        },

        domainInput(event) {
            if (event.target.id !== 'domain') {
                return;
            }
            const domain = event.target.value.trim().toLowerCase().replace(/\.$/, '');
            const labels = domain.split('.').filter(Boolean);
            this.main = domain === '' || (!domain.startsWith('www.')
                && (labels.length <= 2 || (labels.length === 3 && this.suffixes.includes(`${labels[1]}.${labels[2]}`))));
            if (!this.main) {
                this.www = false;
            } else if (!this.touched) {
                this.www = true;
            }
        },

        touch() {
            this.touched = true;
        },

        get sub() {
            return !this.main;
        },
    }));
};

// Livewire brings Alpine. Its script may run before or after this module.
if (window.Alpine) {
    register(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => register(window.Alpine), { once: true });
}
