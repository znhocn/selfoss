import selfoss from './selfoss-base';

selfoss.shares = {
    initialized: false,
    sharers: {},
    names: {},
    enabledShares: '',

    init: function(enabledShares) {
        this.enabledShares = enabledShares;
        this.initialized = true;

        this.register('diaspora', 'd', function(url, title) {
            window.open('https://share.diasporafoundation.org/?url=' + encodeURIComponent(url) + '&title=' + encodeURIComponent(title));
        });
        this.register('twitter', 't', function(url, title) {
            window.open('https://twitter.com/intent/tweet?source=webclient&text=' + encodeURIComponent(title) + ' ' + encodeURIComponent(url));
        });
        this.register('facebook', 'f', function(url, title) {
            window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url) + '&t=' + encodeURIComponent(title));
        });
        this.register('pocket', 'p', function(url, title) {
            window.open('https://getpocket.com/save?url=' + encodeURIComponent(url) + '&title=' + encodeURIComponent(title));
        });

        if (selfoss.config.wallabag !== null) {
            this.register('wallabag', 'w', function(url) {
                if (selfoss.config.wallabag.version === 2) {
                    window.open(selfoss.config.wallabag.url + '/bookmarklet?url=' + encodeURIComponent(url));
                } else {
                    window.open(selfoss.config.wallabag.url + '/?action=add&url=' + btoa(url));
                }
            });
        }

        if (selfoss.config.wordpress !== null) {
            this.register('wordpress', 's', function(url, title) {
                window.open(selfoss.config.wordpress + '/wp-admin/press-this.php?u=' + encodeURIComponent(url) + '&t=' + encodeURIComponent(title));
            });
        }

        this.register('mail', 'e', function(url, title) {
            document.location.href = 'mailto:?body=' + encodeURIComponent(url) + '&subject=' + encodeURIComponent(title);
        });
    },

    register: function(name, id, sharer) {
        if (!this.initialized) {
            return false;
        }
        this.sharers[name] = sharer;
        this.names[id] = name;
        return true;
    },

    getAll: function() {
        var allNames = [];
        if (this.enabledShares != null) {
            for (var i = 0; i < this.enabledShares.length; i++) {
                var enabledShare = this.enabledShares[i];
                if (enabledShare in this.names) {
                    allNames.push(this.names[enabledShare]);
                }
            }
        }
        return allNames;
    },

    share: function(name, url, title) {
        this.sharers[name](url, title);
    },

    buildLinks: function(shares, linkBuilder) {
        var links = '';
        if (shares != null) {
            for (var i = 0; i < shares.length; i++) {
                var name = shares[i];
                links += linkBuilder(name);
            }
        }
        return links;
    }
};
