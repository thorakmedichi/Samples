var MessageItem = React.createClass({

	getInitialState: function() {
		return { hasUnreadButton : (this.props.type !== 'payment' && this.props.type !== 'system') };
	},

	markCommunicationAsUnread: function(id) {
		var url = '/messages/unread';
		var data = {
			id : id
		};

		$.ajax({
			method: "POST",
			url: url,
			data: data,
			dataType: 'json',
			cache: false,
			success: function(data) {
				if (data.success === 1) {
					this.setState({hasUnreadButton : false})
				}
			}.bind(this),

			error: function(xhr, status, err) {
				console.error(this.props.url, status, err.toString());
			}.bind(this)
		});
	},

	render: function() {

		var type = this.props.type;
		var typeIcons = this.props.typeIcons;
		var direction = this.props.outbound_message ? 'out' : 'in';

		var typeDisplay = typeIcons[type];
		var typeIcon = typeDisplay.icon;
		var typeColor = typeDisplay['color_'+ direction];

		var systemGenerated = this.props.system_generated ? 'system' : '';

		var subject = type == 'shipment' ? '<a href="https://tools.usps.com/go/TrackConfirmAction.action?tLabels='+ this.props.subject +'" target="_blank">'+ this.props.subject +'</a>' : this.props.subject;

		var timeline_unread_button;
		if (this.state.hasUnreadButton) {
			timeline_unread_button = <div className="timeline-unread"><a onClick={this.markCommunicationAsUnread.bind(null, this.props.id)} className="btn btn-info btn-xs">Mark as Unread</a></div>
		}

		return (
			<div className="infinite-list-item">
				<div className={"timeline-entry "+ type +" "+ systemGenerated}>
					<div className="timeline-stat">
						<div className={"timeline-icon bg-"+ typeColor}><i className={"fa fa-2x fa-lg "+ typeIcon}></i></div>
						<div className="timeline-time">{this.props.time}</div>
						{timeline_unread_button}
					</div>
					<div className="timeline-label">
						<p>
							<strong className={"text-"+ typeColor} dangerouslySetInnerHTML={{__html: subject}}></strong>
						</p>
						<p className="mar-no pad-btm" dangerouslySetInnerHTML={{__html: this.props.message}}></p>
						<small>{this.props.status}</small>
					</div>
				</div>
			</div>
		);
	}
});

var MessageList = React.createClass({

	render: function() {
		// If there are no messages found lets let the user know
		if (this.props.content.length === 0){
			return (
				<div id={"history-"+ this.props.type +"-timeline"} className="timeline two-column">
					NO MESSAGES
				</div>
			);
		}

		// Loop through each of the message arrays and create the HTML elements for it
		return (
			<div id={"history-"+ this.props.type +"-timeline"} className="timeline two-column">
				<div className="nano">
					<div className="nano-content">
						{this.props.content.map(function(item, i){
							return (
								<MessageItem key={i} typeIcons={this.props.typeIcons} {...item} />
							);
						}, this)}
					</div>
				</div>
			</div>
		);
	}
});

var HistoryPanelContent = React.createClass({

	render: function() {
		
		if (this.props.loadingContent){
			return (
				<div className="panel-body">
					<div className="tab-content">
						<img src="/img/pre-loader.gif" alt="Loading..." className="loader-icon" />
					</div>
				</div>
			);
		}

		// Loop through each of the tabs and output the html 
		// needed to make a list box to hold the messages for this group type
		return (
			<div className="panel-body">
				<div className="tab-content">
					{this.props.tabs.map(function(type, i) {
						var active = ((type == this.props.selectedTab) ? 'active in' : '');
						return (
								<div key={i} id={"history-tab-"+ i} className={"tab-pane fade "+ active }>
									<MessageList content={this.props.content} type={type} typeIcons={this.props.typeIcons}/>
								</div>
							);
					}, this)}
				</div>
			</div>
		);
	}
});

var HistoryPanelTab = React.createClass({
	render: function (){
		var active = (this.props.selectedTab ? 'active' : '');

		return (
			<li key={this.props.key} className={active}>
				<a data-toggle="tab" onClick={this.props.onClick} href={this.props.href}>{this.props.type.ucfirst()}</a>
			</li>
		);
	}
});

var HistoryPanelHeading = React.createClass({

	handleClick: function(type){
		// Fire function that exists in parent component
		// I need this callback function so that I can update
		// the PanelContent, the sibling of PanelHeading
		// because this state does not propogate down this chain
		this.props.onTabChange(type); 
	},

	render: function(){
		// Loop through each of the tab types and build the html to display the tabs
		var historyPanelTab = this.props.tabs.map(function(type, i) {
			var selectedTab = type === this.props.selectedTab ? true : false;

			return (
				<HistoryPanelTab key={i} href={"#history-tab-"+i} onClick={this.handleClick.bind(this, type)} type={type} selectedTab={selectedTab} />
			);
		}, this );

		return (
			<div className="panel-heading">
				<div className="panel-control">
					<ul className="nav nav-tabs">
						{historyPanelTab}
					</ul>
				</div>
				<h3 className="panel-title">History</h3>
			</div>
		);
	}
});

var MessageHistoryPanel = React.createClass({
	getDefaultProps: function() {
		return {
			title: 'History',
			tabs: ['all', 'sms', 'email', 'note', 'shipment', 'mail', 'payment', 'system'],
			typeIcons: {
				sms: {
					icon: 'fa-mobile',
					color_in: 'success',
					color_out: 'info'
				},
				email: {
					icon: 'fa-at',
					color_in: 'success',
					color_out: 'info'
				},
				note: {
					icon: 'fa-comment',
					color_in: 'success',
					color_out: 'warning'
				},
				shipment: {
					icon: 'fa-ship',
					color_in: 'success',
					color_out: 'warning'
				},
				mail: {
					icon: 'fa-envelope',
					color_in: 'success',
					color_out: 'info'
				},
				payment: {
					icon: 'fa-money',
					color_out: 'mint'
				},
				system: {
					icon: 'fa-exclamation-triangle',
					color_out: 'danger'
				}
			}
		};
	},

	getInitialState: function() {
		return {
			selectedTab: 'all',
			content: [],
			loadingContent: true
		};
	},

	componentDidMount: function () {
		// Set our default tab view
		this.getContent('all');
	},

	componentDidUpdate: function() {
		$(".nano").nanoScroller();
	},

	onTabChange: function(type){
		this.getContent(type);
	},

	getContent: function (type){
		var url = '/customers/'+ this.props.customerId +'/message-history/'+ type;
		$.ajax({
			url: url,
			dataType: 'json',
			cache: false,
			beforeSend: function () {
				this.setState({
					content: [],
					loadingContent: true
				});
			}.bind(this),

			success: function(data) {
				this.setState({
					content: data,
					loadingContent: false
				});
			}.bind(this),

			error: function(xhr, status, err) {
				console.error(this.props.url, status, err.toString());
				this.setState({
					disabled: false,
					response: {
						type: 'error',
						message: 'There was an issue sending your message. Please contact the Administrator!'
					}
				});
			}.bind(this)
		});
	},

	render: function(){
		return (
			<div className="panel panel-primary">
				<PanelHeading tabs={this.props.tabs} selectedTab={this.state.selectedTab} onTabChange={this.onTabChange} title={this.props.title} />
				<PanelContent tabs={this.props.tabs} selectedTab={this.state.selectedTab} typeIcons={this.props.typeIcons}>
					<MessageList content={this.state.content} typeIcons={this.props.typeIcons} />
				</PanelContent>
			</div>
		);
	}
});



var customerId = window.customerId;
ReactDOM.render(
		<MessageHistoryPanel customerId={customerId} />,
		document.getElementById('messageHistoryPanel')
);	

